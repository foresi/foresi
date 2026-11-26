<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Meetings\Application\Api\CreateMeetingInput;
use Foresi\Meetings\Application\Api\RestoreMeetingInput;
use Foresi\Meetings\Application\Api\SetMinutesInput;
use Foresi\Meetings\Application\Api\TrashMeetingInput;
use Foresi\Meetings\Application\Api\UpdateMeetingInput;
use Foresi\Meetings\Contract\Event\MeetingCreated;
use Foresi\Meetings\Contract\Event\MeetingMinutesChanged;
use Foresi\Meetings\Contract\Event\MeetingRestored;
use Foresi\Meetings\Contract\Event\MeetingTrashed;
use Foresi\Meetings\Contract\Event\MeetingUpdated;
use Foresi\Meetings\Contract\Field\MeetingExtension;
use Foresi\Meetings\Domain\Attendee;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingLink;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Domain\SeriesMember;
use Foresi\Meetings\Domain\StaleMinutes;
use Foresi\Shared\Application\Field\CustomFieldWrites;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Domain\Id;

/**
 * The writes of the meetings, from the screens, the API and MCP alike (ADR
 * 0032), each in one unit of work with its meetings.meeting event. Whoever sees
 * a meeting changes it and writes its minutes; its creator and the owners put it
 * in the trash and restore it (MeetingVisibility). A new meeting of a series
 * copies the series' agenda template into its minutes and starts with its
 * default attendees; a later change of the series touches neither. The custom
 * fields of a meeting (ADR 0045) come from its screens beside the input and are
 * written in the same unit of work.
 */
final readonly class MeetingWrites
{
    private const string TIME = '/^([01]\d|2[0-3]):[0-5]\d$/D';

    public function __construct(
        private MeetingVisibility $visibility,
        private MeetingPeople $people,
        private MeetingsStore $store,
        private MeetingsWork $work,
        private MeetingTargets $targets,
        private EventRecorder $events,
        private MeetingsClock $clock,
        private AuditActor $actor,
        private CustomFieldWrites $customFields,
    ) {
    }

    /**
     * @param array<string, mixed>|null $customFields the custom fields by key; null when the caller offers none
     *
     * @throws MeetingsRefused
     * @throws FieldRefused    nothing is stored
     */
    public function create(CreateMeetingInput $input, ?array $customFields = null): Meeting
    {
        $this->visibility->denyUnlessStaff();
        $series = null;
        if (null !== $input->seriesId && '' !== $input->seriesId) {
            $series = Id::isValid($input->seriesId) ? $this->store->series($input->seriesId) : null;
            if (!$series instanceof MeetingSeries || !$this->visibility->seesSeries($series)) {
                throw MeetingsRefused::seriesNotFound('seriesId');
            }
            if ($series->isArchived()) {
                throw MeetingsRefused::seriesArchived();
            }
        }
        $heldOn = $this->day($input->heldOn);
        $title = null === $input->title || '' === trim($input->title)
            ? ($series instanceof MeetingSeries ? self::defaultTitle($series, $heldOn) : throw MeetingsRefused::invalid('title_required', 'title', 'A meeting without a series needs a title.'))
            : $this->title($input->title);
        $startsAt = $this->time($input->startsAt);
        $attendees = $this->attendees($input->attendeeIds ?? ($series instanceof MeetingSeries ? $this->defaultAttendees($series) : []));
        $links = $this->links($input->links);
        $minutes = $this->minutes($input->minutes ?? ($series?->agendaTemplate() ?? ''));

        return $this->work->run(function () use ($series, $title, $heldOn, $startsAt, $minutes, $attendees, $links, $customFields): Meeting {
            $meeting = new Meeting($series, $title, $heldOn, $startsAt, $minutes, $this->actor->userId() ?? '', $this->clock->now());
            $this->store->add($meeting);
            foreach ($attendees as $membershipId) {
                $this->store->add(new Attendee($meeting, $membershipId));
            }
            foreach ($links as [$kind, $id]) {
                $this->store->add(new MeetingLink($meeting, $kind, $id));
            }
            if (null !== $customFields) {
                $this->customFields->write(MeetingExtension::RECORD, $meeting->id(), $customFields, true);
            }
            $this->events->record(new MeetingCreated($meeting->id(), $series?->id()));

            return $meeting;
        });
    }

    /**
     * @param array<string, mixed>|null $customFields the custom fields to change by key, null clearing one; null when the caller offers none
     *
     * @throws MeetingsRefused
     * @throws FieldRefused    nothing is stored
     */
    public function update(UpdateMeetingInput $input, ?array $customFields = null): Meeting
    {
        return $this->work->run(function () use ($input, $customFields): Meeting {
            $meeting = $this->open($input->meetingId);
            $meeting->reschedule(
                $input->title instanceof Omitted ? $meeting->title() : $this->title($input->title),
                $input->heldOn instanceof Omitted ? $meeting->heldOn() : $this->day($input->heldOn),
                $input->startsAt instanceof Omitted ? $meeting->startsAt() : $this->time($input->startsAt),
            );
            if (!$input->attendeeIds instanceof Omitted) {
                $current = $this->store->attendees($meeting);
                $kept = array_map(static fn (Attendee $attendee): string => $attendee->membershipId(), $current);
                $wanted = $this->attendees($input->attendeeIds, $kept);
                foreach ($current as $attendee) {
                    if (!\in_array($attendee->membershipId(), $wanted, true)) {
                        $this->store->remove($attendee);
                    }
                }
                foreach (array_diff($wanted, $kept) as $membershipId) {
                    $this->store->add(new Attendee($meeting, $membershipId));
                }
            }
            if (!$input->links instanceof Omitted) {
                $current = $this->store->links($meeting);
                $wanted = [];
                foreach ($this->links($input->links, $current) as [$kind, $id]) {
                    $wanted[$kind.':'.$id] = [$kind, $id];
                }
                foreach ($current as $link) {
                    if (!isset($wanted[$link->kind().':'.$link->targetId()])) {
                        $this->store->remove($link);
                    }
                    unset($wanted[$link->kind().':'.$link->targetId()]);
                }
                foreach ($wanted as [$kind, $id]) {
                    $this->store->add(new MeetingLink($meeting, $kind, $id));
                }
            }
            $changed = null === $customFields ? [] : $this->customFields->write(MeetingExtension::RECORD, $meeting->id(), $customFields, false);
            $this->events->record(new MeetingUpdated($meeting->id(), $meeting->series()?->id(), $changed));

            return $meeting;
        });
    }

    /**
     * @throws MeetingsRefused stale_minutes when someone saved the minutes since the version given
     */
    public function setMinutes(SetMinutesInput $input): Meeting
    {
        return $this->work->run(function () use ($input): Meeting {
            $meeting = $this->open($input->meetingId);
            try {
                $changed = $meeting->writeMinutes($this->minutes($input->minutes), $input->version);
            } catch (StaleMinutes $stale) {
                throw MeetingsRefused::staleMinutes($stale->currentVersion);
            }
            if ($changed) {
                $this->events->record(new MeetingMinutesChanged($meeting->id(), $meeting->series()?->id(), $meeting->version()));
            }

            return $meeting;
        });
    }

    /**
     * @throws MeetingsRefused
     */
    public function trash(TrashMeetingInput $input): Meeting
    {
        return $this->work->run(function () use ($input): Meeting {
            $meeting = $this->trashable($input->meetingId);
            if ($meeting->trash($this->actor->userId() ?? '', $this->clock->now())) {
                $this->events->record(new MeetingTrashed($meeting->id(), $meeting->series()?->id()));
            }

            return $meeting;
        });
    }

    /**
     * @throws MeetingsRefused
     */
    public function restore(RestoreMeetingInput $input): Meeting
    {
        return $this->work->run(function () use ($input): Meeting {
            $meeting = $this->trashable($input->meetingId);
            if ($meeting->restore()) {
                $this->events->record(new MeetingRestored($meeting->id(), $meeting->series()?->id()));
            }

            return $meeting;
        });
    }

    /**
     * The title of a new meeting of a series without one: the series name and the day, such as "Production weekly – 2026-12-01".
     */
    public static function defaultTitle(MeetingSeries $series, \DateTimeImmutable $heldOn): string
    {
        return mb_substr($series->name().' – '.$heldOn->format('Y-m-d'), 0, Meeting::MAX_TITLE);
    }

    /**
     * @return array<int, string> the membership ids of the default attendees of the series who are still in the firm
     */
    private function defaultAttendees(MeetingSeries $series): array
    {
        return array_filter(array_map(
            static fn (SeriesMember $member): ?string => $member->isDefaultAttendee() ? $member->membershipId() : null,
            $this->store->members($series),
        ), fn (?string $id): bool => null !== $id && $this->people->isStaff($id));
    }

    /**
     * A meeting the person sees and that is not in the trash.
     *
     * @throws MeetingsRefused
     */
    private function open(string $id): Meeting
    {
        $meeting = $this->seen($id);
        if ($meeting->isTrashed()) {
            throw MeetingsRefused::trashed();
        }

        return $meeting;
    }

    /**
     * @throws MeetingsRefused
     */
    private function trashable(string $id): Meeting
    {
        $meeting = $this->seen($id);
        if (!$this->visibility->mayTrash($meeting)) {
            throw MeetingsRefused::notTrasher();
        }

        return $meeting;
    }

    /**
     * @throws MeetingsRefused
     */
    private function seen(string $id): Meeting
    {
        $this->visibility->denyUnlessStaff();
        $meeting = Id::isValid($id) ? $this->store->meeting($id) : null;
        if (!$meeting instanceof Meeting || !$this->visibility->sees($meeting)) {
            throw MeetingsRefused::meetingNotFound();
        }

        return $meeting;
    }

    /**
     * @throws MeetingsRefused
     */
    private function title(string $title): string
    {
        $title = trim($title);
        if ('' === $title) {
            throw MeetingsRefused::invalid('title_required', 'title', 'The meeting needs a title.');
        }
        if (mb_strlen($title) > Meeting::MAX_TITLE) {
            throw MeetingsRefused::invalid('title_too_long', 'title', \sprintf('The title has at most %d characters.', Meeting::MAX_TITLE));
        }

        return $title;
    }

    /**
     * @throws MeetingsRefused
     */
    private function day(string $value): \DateTimeImmutable
    {
        $day = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw MeetingsRefused::invalid('held_on_invalid', 'heldOn', 'The day is a date, YYYY-MM-DD.');
        }

        return $day;
    }

    /**
     * @throws MeetingsRefused
     */
    private function time(?string $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        $value = trim($value);
        if (1 !== preg_match(self::TIME, $value)) {
            throw MeetingsRefused::invalid('starts_at_invalid', 'startsAt', 'The time is HH:MM, such as 09:30.');
        }

        return $value;
    }

    /**
     * @throws MeetingsRefused
     */
    private function minutes(string $minutes): string
    {
        if (mb_strlen($minutes) > Meeting::MAX_MINUTES) {
            throw MeetingsRefused::invalid('minutes_too_long', 'minutes', \sprintf('The minutes have at most %d characters.', Meeting::MAX_MINUTES));
        }

        return $minutes;
    }

    /**
     * The attendees as they will be: people of the firm, once each. An attendee who
     * left the firm may stay on the meeting; nobody new may.
     *
     * @param array<mixed> $ids
     * @param list<string> $kept
     *
     * @return array<string, string> the membership ids, each by itself
     *
     * @throws MeetingsRefused
     */
    private function attendees(array $ids, array $kept = []): array
    {
        $attendees = [];
        foreach ($ids as $id) {
            if (!\is_string($id) || (!$this->people->isStaff($id) && !\in_array($id, $kept, true))) {
                throw MeetingsRefused::invalid('attendee_unknown', 'attendeeIds', 'An attendee is not an owner or member of the firm. Find the people with foresi_list_meeting_series; link people from outside as contacts.');
            }
            $attendees[$id] = $id;
        }

        return $attendees;
    }

    /**
     * The records as they will be linked, once each: a record kept may have gone
     * since, a new one must be one the person may link.
     *
     * @param list<mixed>       $links
     * @param list<MeetingLink> $kept
     *
     * @return array<string, array{string, string}> the kind and id of each, by "kind:id"
     *
     * @throws MeetingsRefused
     */
    private function links(array $links, array $kept = []): array
    {
        $keep = [];
        foreach ($kept as $link) {
            $keep[$link->kind().':'.$link->targetId()] = true;
        }
        $result = [];
        foreach ($links as $link) {
            $kind = \is_array($link) ? ($link['kind'] ?? null) : null;
            $id = \is_array($link) ? ($link['id'] ?? null) : null;
            if (!\is_string($kind) || !\is_string($id) || !$this->targets->has($kind)) {
                throw MeetingsRefused::invalid('link_invalid', 'links', \sprintf('A link is {"kind", "id"}, with a kind of %s.', implode(', ', $this->targets->kinds())));
            }
            if (!isset($keep[$kind.':'.$id]) && !$this->targets->canLink($kind, $id)) {
                throw MeetingsRefused::invalid('link_not_found', 'links', \sprintf('There is no %s with the id %s that you may link.', $kind, $id));
            }
            $result[$kind.':'.$id] = [$kind, $id];
        }

        return $result;
    }
}
