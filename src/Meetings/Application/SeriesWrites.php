<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Meetings\Application\Api\ArchiveSeriesInput;
use Foresi\Meetings\Application\Api\CreateSeriesInput;
use Foresi\Meetings\Application\Api\UpdateSeriesInput;
use Foresi\Meetings\Contract\Event\SeriesArchived;
use Foresi\Meetings\Contract\Event\SeriesCreated;
use Foresi\Meetings\Contract\Event\SeriesUpdated;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Domain\SeriesMember;
use Foresi\Meetings\Domain\SeriesVisibility;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Contract\ProjectDirectory;

/**
 * The writes of the series, from the screens, the API and MCP alike (ADR 0032),
 * each in one unit of work with its meetings.series event. The owners and the
 * members of a series change it; anyone in the firm creates one and becomes its
 * member. Archiving an archived series changes nothing and records nothing.
 */
final readonly class SeriesWrites
{
    public function __construct(
        private MeetingVisibility $visibility,
        private MeetingPeople $people,
        private MeetingsStore $store,
        private MeetingsWork $work,
        private EventRecorder $events,
        private MeetingsClock $clock,
        private ProjectDirectory $projects,
        private AuditActor $actor,
    ) {
    }

    /**
     * @throws MeetingsRefused
     */
    public function create(CreateSeriesInput $input): MeetingSeries
    {
        $this->visibility->denyUnlessStaff();
        $name = $this->name($input->name, null);
        $visibility = $this->visibilityOf($input->visibility);
        $template = $this->template($input->agendaTemplate);
        $project = $this->project($input->defaultProjectId);
        $me = $this->people->me();
        $members = $this->members([...$input->memberIds, ...(null === $me ? [] : [$me])], $input->defaultAttendeeIds);

        return $this->work->run(function () use ($name, $visibility, $template, $project, $members): MeetingSeries {
            $series = new MeetingSeries($name, $visibility, $template, $project, $this->actor->userId() ?? '', $this->clock->now());
            $this->store->add($series);
            foreach ($members as $membershipId => $default) {
                $this->store->add(new SeriesMember($series, $membershipId, $default));
            }
            $this->events->record(new SeriesCreated($series->id()));
            $this->visibility->forget();

            return $series;
        });
    }

    /**
     * @throws MeetingsRefused
     */
    public function update(UpdateSeriesInput $input): MeetingSeries
    {
        return $this->work->run(function () use ($input): MeetingSeries {
            $series = $this->changeable($input->seriesId);
            $series->change(
                $input->name instanceof Omitted ? $series->name() : $this->name($input->name, $series),
                $input->visibility instanceof Omitted ? $series->visibility() : $this->visibilityOf($input->visibility),
                $input->agendaTemplate instanceof Omitted ? $series->agendaTemplate() : $this->template($input->agendaTemplate),
                $input->defaultProjectId instanceof Omitted ? $series->defaultProjectId() : $this->project($input->defaultProjectId),
            );
            if (!$input->memberIds instanceof Omitted || !$input->defaultAttendeeIds instanceof Omitted) {
                $current = $this->store->members($series);
                $ids = $input->memberIds instanceof Omitted ? array_map(static fn (SeriesMember $member): string => $member->membershipId(), $current) : $input->memberIds;
                $defaults = $input->defaultAttendeeIds instanceof Omitted
                    ? array_values(array_map(static fn (SeriesMember $member): string => $member->membershipId(), array_filter($current, static fn (SeriesMember $member): bool => $member->isDefaultAttendee())))
                    : $input->defaultAttendeeIds;
                // A member taken off is no default attendee any more.
                $defaults = $input->defaultAttendeeIds instanceof Omitted ? array_values(array_intersect($defaults, $ids)) : $defaults;
                $this->replaceMembers($series, $current, $this->members($ids, $defaults, $current));
            }
            $this->events->record(new SeriesUpdated($series->id()));
            $this->visibility->forget();

            return $series;
        });
    }

    /**
     * @throws MeetingsRefused
     */
    public function archive(ArchiveSeriesInput $input): MeetingSeries
    {
        return $this->work->run(function () use ($input): MeetingSeries {
            $series = $this->changeable($input->seriesId);
            if ($series->archive($this->clock->now())) {
                $this->events->record(new SeriesArchived($series->id()));
            }

            return $series;
        });
    }

    /**
     * @throws MeetingsRefused not_found when the person does not see it, not_series_member when they see it but may not change it
     */
    private function changeable(string $id): MeetingSeries
    {
        $this->visibility->denyUnlessStaff();
        $series = Id::isValid($id) ? $this->store->series($id) : null;
        if (!$series instanceof MeetingSeries || !$this->visibility->seesSeries($series)) {
            throw MeetingsRefused::seriesNotFound();
        }
        if (!$this->visibility->mayChangeSeries($series)) {
            throw MeetingsRefused::notSeriesMember();
        }

        return $series;
    }

    /**
     * @param list<SeriesMember>  $current
     * @param array<string, bool> $wanted  whether each wanted member attends by default, by membership id
     */
    private function replaceMembers(MeetingSeries $series, array $current, array $wanted): void
    {
        foreach ($current as $member) {
            if (!\array_key_exists($member->membershipId(), $wanted)) {
                $this->store->remove($member);
                continue;
            }
            if ($member->isDefaultAttendee() !== $wanted[$member->membershipId()]) {
                $member->attendByDefault($wanted[$member->membershipId()]);
            }
            unset($wanted[$member->membershipId()]);
        }
        foreach ($wanted as $membershipId => $default) {
            $this->store->add(new SeriesMember($series, $membershipId, $default));
        }
    }

    /**
     * @throws MeetingsRefused
     */
    private function name(string $name, ?MeetingSeries $series): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw MeetingsRefused::invalid('name_required', 'name', 'The series needs a name.');
        }
        if (mb_strlen($name) > MeetingSeries::MAX_NAME) {
            throw MeetingsRefused::invalid('name_too_long', 'name', \sprintf('The name has at most %d characters.', MeetingSeries::MAX_NAME));
        }
        $other = $this->store->seriesNamed($name);
        if ($other instanceof MeetingSeries && $other !== $series) {
            throw MeetingsRefused::nameTaken();
        }

        return $name;
    }

    /**
     * @throws MeetingsRefused
     */
    private function visibilityOf(string $value): SeriesVisibility
    {
        return SeriesVisibility::tryFrom($value) ?? throw MeetingsRefused::invalid('visibility_invalid', 'visibility', 'The visibility is members or organisation.');
    }

    /**
     * @throws MeetingsRefused
     */
    private function template(string $template): string
    {
        if (mb_strlen($template) > MeetingSeries::MAX_TEMPLATE) {
            throw MeetingsRefused::invalid('template_too_long', 'agendaTemplate', \sprintf('The agenda template has at most %d characters.', MeetingSeries::MAX_TEMPLATE));
        }

        return $template;
    }

    /**
     * @throws MeetingsRefused
     */
    private function project(?string $id): ?string
    {
        if (null === $id || '' === $id) {
            return null;
        }
        $project = $this->projects->find($id);
        if (!$project instanceof \Foresi\Work\Contract\ProjectSummary || $project->archived) {
            throw MeetingsRefused::invalid('project_not_found', 'defaultProjectId', 'There is no active project with this id that you see. Find it with foresi_search_projects.');
        }

        return $project->id;
    }

    /**
     * The members as they will be: each a person of the firm, once, the default
     * attendees among them. A member who left the firm may stay; nobody new may.
     *
     * @param list<mixed>        $ids
     * @param list<mixed>        $defaults
     * @param list<SeriesMember> $current
     *
     * @return array<string, bool> whether each attends by default, by membership id
     *
     * @throws MeetingsRefused
     */
    private function members(array $ids, array $defaults, array $current = []): array
    {
        $kept = array_map(static fn (SeriesMember $member): string => $member->membershipId(), $current);
        $members = [];
        foreach ([...$ids, ...$defaults] as $id) {
            if (!\is_string($id) || (!$this->people->isStaff($id) && !\in_array($id, $kept, true))) {
                throw MeetingsRefused::invalid('member_unknown', 'memberIds', 'A member is not an owner or member of the firm. Find the people with foresi_list_meeting_series.');
            }
            $members[$id] = \in_array($id, $defaults, true);
        }

        return $members;
    }
}
