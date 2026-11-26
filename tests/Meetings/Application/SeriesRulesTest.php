<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Application;

use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Meetings\Application\Api\ArchiveSeriesInput;
use Foresi\Meetings\Application\Api\CreateMeetingInput;
use Foresi\Meetings\Application\Api\CreateSeriesInput;
use Foresi\Meetings\Application\Api\UpdateSeriesInput;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Domain\SeriesMember;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Meetings\Support\MeetingsTestCase;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\WorkflowTemplate;

/**
 * What a series accepts: the limits of its fields, its name taken once, its project, its
 * members and their default attendees, who may change it, and the events it records.
 */
final class SeriesRulesTest extends MeetingsTestCase
{
    public function testAGuestChangesNothing(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', 'organisation'));
        $this->signIn($this->guest);

        foreach ([
            fn (): mixed => $this->seriesWrites()->create(new CreateSeriesInput('Hidden')),
            fn (): mixed => $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), name: 'Changed')),
            fn (): mixed => $this->seriesWrites()->archive(new ArchiveSeriesInput($series->id())),
            fn (): mixed => $this->seriesReads()->one($series->id()),
            fn (): mixed => $this->seriesReads()->all(),
        ] as $write) {
            self::assertSame('forbidden', $this->refusal($write)->reason());
        }
    }

    public function testTheCreatorIsAlwaysAMemberAndIsRecorded(): void
    {
        $alone = $this->seriesWrites()->create(new CreateSeriesInput('Alone'));
        self::assertSame([$this->owner->id()], array_column($this->seriesReads()->view($alone->id())->members, 'id'));
        self::assertSame($this->owner->user()->id(), $alone->createdBy());

        $with = $this->seriesWrites()->create(new CreateSeriesInput('Together', memberIds: [$this->chief->id(), $this->member->id()], defaultAttendeeIds: [$this->member->id()]));
        $members = array_column($this->seriesReads()->view($with->id())->members, 'default_attendee', 'id');
        ksort($members);
        $expected = [$this->owner->id() => false, $this->chief->id() => false, $this->member->id() => true];
        ksort($expected);
        self::assertSame($expected, $members);
    }

    public function testTheMembersOfASeriesAreSortedByName(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', memberIds: [$this->member->id(), $this->chief->id()]));

        self::assertSame(['Charlie Chief', 'Morgan Member', 'Olivia Owner'], array_column($this->seriesReads()->view($series->id())->members, 'name'));
    }

    public function testTheNameIsTrimmedUniqueAndWithin120Characters(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('  Production weekly  '));
        self::assertSame('Production weekly', $series->name());

        $long = str_repeat('é', MeetingSeries::MAX_NAME);
        self::assertSame($long, $this->seriesWrites()->create(new CreateSeriesInput($long))->name());
        $refused = $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->create(new CreateSeriesInput($long.'x')));
        self::assertSame(['name_too_long', 'name', 'The name has at most 120 characters.'], [$refused->reason(), $refused->field(), $refused->getMessage()]);
        foreach (['', '   '] as $blank) {
            $refused = $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->create(new CreateSeriesInput($blank)));
            self::assertSame(['name_required', 'name', 'The series needs a name.'], [$refused->reason(), $refused->field(), $refused->getMessage()]);
        }

        $other = $this->seriesWrites()->create(new CreateSeriesInput('Sales weekly'));
        self::assertSame('name_taken', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->create(new CreateSeriesInput('Production weekly')))->reason());
        self::assertSame('name_taken', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->update(new UpdateSeriesInput($other->id(), name: ' Production weekly ')))->reason());
        // A series keeps its own name when it is saved again, and may take a free one.
        self::assertSame('Production weekly', $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), name: 'Production weekly'))->name());
        self::assertSame('Sales weekly 2', $this->seriesWrites()->update(new UpdateSeriesInput($other->id(), name: 'Sales weekly 2'))->name());
        self::assertSame('name_too_long', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->update(new UpdateSeriesInput($other->id(), name: $long.'x')))->reason());
        self::assertSame('name_required', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->update(new UpdateSeriesInput($other->id(), name: ' ')))->reason());
    }

    public function testTheAgendaTemplateIsKeptWithin20000Characters(): void
    {
        $full = str_repeat('é', MeetingSeries::MAX_TEMPLATE);
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', agendaTemplate: $full));
        self::assertSame($full, $series->agendaTemplate());

        $refused = $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->create(new CreateSeriesInput('Other weekly', agendaTemplate: $full.'x')));
        self::assertSame(['template_too_long', 'agendaTemplate', 'The agenda template has at most 20000 characters.'], [$refused->reason(), $refused->field(), $refused->getMessage()]);
        self::assertSame('template_too_long', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), agendaTemplate: $full.'x')))->reason());
    }

    public function testTheVisibilityIsMembersOrOrganisation(): void
    {
        $refused = $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', 'everybody')));
        self::assertSame(['visibility_invalid', 'visibility', 'The visibility is members or organisation.'], [$refused->reason(), $refused->field(), $refused->getMessage()]);

        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', 'organisation'));
        self::assertSame('visibility_invalid', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), visibility: 'nobody')))->reason());
        self::assertSame('members', $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), visibility: 'members'))->visibility()->value);
    }

    public function testTheDefaultProjectIsAnActiveProjectOrNone(): void
    {
        $project = $this->project('Website');
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', defaultProjectId: $project->id()));
        self::assertSame($project->id(), $series->defaultProjectId());

        foreach ([Id::generate(), 'not-an-id'] as $id) {
            $refused = $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->create(new CreateSeriesInput('Other weekly', defaultProjectId: $id)));
            self::assertSame(['project_not_found', 'defaultProjectId'], [$refused->reason(), $refused->field()]);
        }
        // An empty id and null mean none.
        self::assertNull($this->seriesWrites()->create(new CreateSeriesInput('Empty weekly', defaultProjectId: ''))->defaultProjectId());
        self::assertNull($this->seriesWrites()->update(new UpdateSeriesInput($series->id(), defaultProjectId: null))->defaultProjectId());
        self::assertSame($project->id(), $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), defaultProjectId: $project->id()))->defaultProjectId());

        $project->archive(new \DateTimeImmutable('2026-11-26 11:00'));
        $this->em->flush();
        self::assertSame('project_not_found', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->create(new CreateSeriesInput('Archived weekly', defaultProjectId: $project->id())))->reason());
    }

    public function testOnlyPeopleOfTheFirmAreMembers(): void
    {
        foreach ([[$this->guest->id()], [Id::generate()], [42], [null], [$this->chief->id(), $this->guest->id()]] as $ids) {
            $refused = $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->create(new \ReflectionClass(CreateSeriesInput::class)->newInstanceArgs(['name' => 'Production weekly', 'memberIds' => $ids])));
            self::assertSame(['member_unknown', 'memberIds'], [$refused->reason(), $refused->field()]);
        }
        // A default attendee is made a member, and a guest cannot be one.
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', defaultAttendeeIds: [$this->chief->id()]));
        self::assertEquals([$this->chief->id() => true, $this->owner->id() => false], array_column($this->seriesReads()->view($series->id())->members, 'default_attendee', 'id'));
        self::assertSame('member_unknown', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->create(new CreateSeriesInput('Other weekly', defaultAttendeeIds: [$this->guest->id()])))->reason());
        self::assertSame('member_unknown', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), memberIds: [$this->guest->id()])))->reason());
    }

    public function testTheMembersAreReplacedAndTheDefaultAttendeesFollowThem(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', memberIds: [$this->chief->id(), $this->member->id()], defaultAttendeeIds: [$this->chief->id(), $this->member->id()]));

        // New members only: the defaults that stay members stay defaults.
        $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), memberIds: [$this->owner->id(), $this->member->id()]));
        self::assertEquals([$this->member->id() => true, $this->owner->id() => false], $this->members($series));

        // New defaults only: the members stay.
        $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), defaultAttendeeIds: [$this->owner->id()]));
        self::assertEquals([$this->owner->id() => true, $this->member->id() => false], $this->members($series));

        // Both: a member added with no default, one made default.
        $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), memberIds: [$this->owner->id(), $this->member->id(), $this->chief->id()], defaultAttendeeIds: [$this->member->id()]));
        self::assertEquals([$this->chief->id() => false, $this->member->id() => true, $this->owner->id() => false], $this->members($series));

        // Neither: the members stay as they are.
        $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), name: 'Production daily'));
        self::assertEquals([$this->chief->id() => false, $this->member->id() => true, $this->owner->id() => false], $this->members($series));
    }

    public function testAMemberWhoLeftTheFirmStaysInTheSeries(): void
    {
        // Built from its rows, so that the firm's people are first read after the member left.
        $series = new MeetingSeries('Production weekly', \Foresi\Meetings\Domain\SeriesVisibility::Members, '', null, $this->owner->user()->id(), new \DateTimeImmutable('2026-11-26 10:00'));
        $this->em->persist($series);
        foreach ([$this->owner, $this->chief, $this->member] as $person) {
            $this->em->persist(new SeriesMember($series, $person->id(), false));
        }
        $this->chief->deactivate(new \DateTimeImmutable('2026-11-26 11:00'));
        $this->em->flush();
        $this->signIn($this->owner);

        $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), memberIds: [$this->owner->id(), $this->chief->id()]));
        self::assertEquals([$this->chief->id() => false, $this->owner->id() => false], $this->members($series));
        self::assertSame('member_unknown', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), memberIds: [$this->owner->id(), $this->guest->id()])))->reason());
    }

    public function testASeriesIsChangedByItsMembersAndTheOwnersOnly(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', 'organisation', memberIds: [$this->chief->id()]));

        // A member of the firm outside the series sees it and may not change it.
        $this->signIn($this->member);
        self::assertFalse($this->seriesReads()->view($series->id())->changeable);
        self::assertSame('not_series_member', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), name: 'Changed')))->reason());
        self::assertSame('not_series_member', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->archive(new ArchiveSeriesInput($series->id())))->reason());

        $this->signIn($this->chief);
        self::assertTrue($this->seriesReads()->view($series->id())->changeable);
        self::assertSame('Chief weekly', $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), name: 'Chief weekly'))->name());

        // The owner is no member and changes it all the same.
        $this->signIn($this->owner);
        $hidden = $this->seriesWrites()->create(new CreateSeriesInput('Private weekly', 'members', memberIds: [$this->chief->id()]));
        $this->seriesWrites()->update(new UpdateSeriesInput($hidden->id(), memberIds: [$this->chief->id()]));
        self::assertTrue($this->seriesReads()->view($hidden->id())->changeable);
    }

    public function testASeriesOutOfSightIsNotFound(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Private weekly', 'members', memberIds: [$this->owner->id()]));
        $this->signIn($this->member);

        foreach ([$series->id(), Id::generate(), 'not-an-id'] as $id) {
            self::assertSame('not_found', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->update(new UpdateSeriesInput($id, name: 'Changed')))->reason());
            self::assertSame('not_found', $this->refusal(fn (): MeetingSeries => $this->seriesWrites()->archive(new ArchiveSeriesInput($id)))->reason());
            self::assertSame('not_found', $this->refusal(fn (): MeetingSeries => $this->seriesReads()->one($id))->reason());
            self::assertSame('not_found', $this->refusal(fn (): mixed => $this->seriesReads()->view($id))->reason());
        }
    }

    public function testArchivingHidesASeriesFromTheListsAndRecordsItOnce(): void
    {
        $kept = $this->seriesWrites()->create(new CreateSeriesInput('Kept weekly', 'organisation'));
        $old = $this->seriesWrites()->create(new CreateSeriesInput('Old weekly', 'organisation'));

        $this->seriesWrites()->archive(new ArchiveSeriesInput($old->id()));
        $this->seriesWrites()->archive(new ArchiveSeriesInput($old->id()));

        self::assertTrue($old->isArchived());
        self::assertEquals(new \DateTimeImmutable('2026-11-26 10:00:00'), $old->archivedAt());
        self::assertSame([$kept->id()], array_map(static fn (MeetingSeries $series): string => $series->id(), $this->seriesReads()->all()));
        self::assertEqualsCanonicalizing([$kept->id(), $old->id()], array_map(static fn (MeetingSeries $series): string => $series->id(), $this->seriesReads()->all(true)));
        self::assertSame(['Kept weekly'], array_map(static fn (\Foresi\Meetings\Application\SeriesView $view): string => $view->series->name(), $this->seriesReads()->views()));
        self::assertCount(2, $this->seriesReads()->views(true));
        self::assertSame([['series_id' => $old->id()]], $this->events('meetings.series.archived'));
    }

    public function testASeriesCountsItsMeetingsAndKnowsTheLastDay(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', 'organisation'));
        $empty = $this->seriesWrites()->create(new CreateSeriesInput('Empty weekly', 'organisation'));
        $this->meetingWrites()->create(new CreateMeetingInput('2026-11-23', $series->id()));
        $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $series->id()));

        $view = $this->seriesReads()->view($series->id());
        self::assertSame(2, $view->meetings);
        self::assertSame('2026-11-30', $view->lastHeldOn?->format('Y-m-d'));
        $none = $this->seriesReads()->view($empty->id());
        self::assertSame(0, $none->meetings);
        self::assertNull($none->lastHeldOn);
    }

    public function testASeriesCreatedByAMemberIsSeenAtOnceAndOneLeftIsNot(): void
    {
        $this->signIn($this->chief);
        // The reads before fill the cache of who belongs to which series.
        self::assertSame([], $this->seriesIds());

        $series = $this->seriesWrites()->create(new CreateSeriesInput('Chief weekly', 'members'));
        self::assertSame([$series->id()], $this->seriesIds());

        // Taking oneself off drops the series from sight at once.
        $this->signIn($this->owner);
        $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), memberIds: [$this->owner->id(), $this->chief->id()]));
        $this->signIn($this->chief);
        self::assertCount(1, $this->seriesIds());
        $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), memberIds: [$this->owner->id()]));
        self::assertSame([], $this->seriesIds());
    }

    public function testTheEventsOfASeries(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly'));
        $this->seriesWrites()->update(new UpdateSeriesInput($series->id(), name: 'Production daily'));

        self::assertSame([['series_id' => $series->id()]], $this->events('meetings.series.created'));
        self::assertSame([['series_id' => $series->id()]], $this->events('meetings.series.updated'));
    }

    /**
     * @return list<string>
     */
    private function seriesIds(): array
    {
        return array_map(static fn (MeetingSeries $series): string => $series->id(), $this->seriesReads()->all());
    }

    /**
     * @param callable(): mixed $do
     */
    private function refusal(callable $do): MeetingsRefused
    {
        try {
            $do();
        } catch (MeetingsRefused $refused) {
            return $refused;
        }
        self::fail('The write was not refused.');
    }

    /**
     * @return array<string, bool> whether each member of the series is a default attendee, by the membership id
     */
    private function members(MeetingSeries $series): array
    {
        $this->em->clear();
        $members = array_column($this->seriesReads()->view($series->id())->members, 'default_attendee', 'id');
        ksort($members);

        return $members;
    }

    private function project(string $name): Project
    {
        $project = new Project($name, null, WorkflowTemplate::Simple, $this->owner->user()->id(), new \DateTimeImmutable('2026-11-26 09:00'));
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /**
     * @return list<array<mixed>> the data of the recorded events of this name, oldest first
     */
    private function events(string $name): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, [$name]);
        $events = [];
        foreach ($rows as $data) {
            self::assertIsString($data);
            $decoded = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $events[] = $decoded;
        }

        return $events;
    }
}
