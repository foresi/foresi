<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Application;

use Foresi\Meetings\Application\Api\TrashMeetingInput;
use Foresi\Meetings\Application\MeetingPeople;
use Foresi\Meetings\Application\MeetingSearch;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Application\PurgeMeetingTrashDaily;
use Foresi\Meetings\Application\PurgeTrashedMeetings;
use Foresi\Meetings\Application\View\MeetingLists;
use Foresi\Meetings\Contract\Field\MeetingExtension;
use Foresi\Meetings\Domain\Attendee;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingLink;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Domain\SeriesMember;
use Foresi\Meetings\Domain\SeriesVisibility;
use Foresi\Meetings\Domain\StaleMinutes;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Tests\Meetings\Support\MeetingsTestCase;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Symfony\Component\Clock\MockClock;

/**
 * Who sees and trashes a meeting in each branch of the rules, how the reads order and
 * page, what the list of the meetings ships, what the domain answers of its changes, and
 * what the purge of the trash removes.
 */
final class MeetingReadsAndRulesTest extends MeetingsTestCase
{
    public function testAMeetingOfASeriesIsSeenByTheOrganisationItsMembersAndItsAttendeesOnly(): void
    {
        $organisation = $this->series('Open weekly', SeriesVisibility::Organisation);
        $closed = $this->series('Closed weekly', SeriesVisibility::Members, [$this->chief]);
        $open = $this->meeting($organisation, 'Open meeting', $this->owner);
        $inClosed = $this->meeting($closed, 'Closed meeting', $this->owner);
        $invited = $this->meeting($closed, 'Invited meeting', $this->owner, [$this->member]);
        $this->em->flush();

        // The whole firm sees a meeting of an organisation series, whoever attends.
        $this->signIn($this->member);
        self::assertTrue($this->visibility()->sees($open));
        self::assertFalse($this->visibility()->sees($inClosed));
        self::assertTrue($this->visibility()->sees($invited));

        // A member of a members series sees its meetings without attending them.
        $this->signIn($this->chief);
        self::assertTrue($this->visibility()->sees($inClosed));
        self::assertTrue($this->visibility()->sees($invited));
    }

    public function testWhoCreatedAMeetingOfASeriesTheyDoNotSeeDoesNotSeeItEither(): void
    {
        $closed = $this->series('Closed weekly', SeriesVisibility::Members);
        $made = $this->meeting($closed, 'Made by a member', $this->member);
        $this->em->flush();

        $this->signIn($this->member);
        self::assertFalse($this->visibility()->sees($made));
        self::assertSame([], array_map(static fn (Meeting $meeting): string => $meeting->id(), $this->meetingReads()->search(new MeetingSearch())));
    }

    public function testOnlyTheCreatorAndTheOwnersTrashAMeeting(): void
    {
        $byMember = $this->meeting(null, 'By a member', $this->member, [$this->chief]);
        $byGuest = $this->meeting(null, 'By a guest', $this->guest);
        $this->em->flush();

        $this->signIn($this->member);
        self::assertTrue($this->visibility()->mayTrash($byMember));
        $this->signIn($this->chief);
        self::assertFalse($this->visibility()->mayTrash($byMember));
        $this->signIn($this->owner);
        self::assertTrue($this->visibility()->mayTrash($byMember));
        // A guest keeps no meetings, even one that was theirs.
        $this->signIn($this->guest);
        self::assertFalse($this->visibility()->mayTrash($byGuest));
        self::assertFalse($this->visibility()->mayUse());
        self::assertFalse($this->visibility()->isOwner());
        self::assertFalse($this->visibility()->sees($byGuest));
    }

    public function testASeriesIsChangedByTheOwnersAndItsMembers(): void
    {
        $closed = $this->series('Closed weekly', SeriesVisibility::Members, [$this->chief]);
        $this->em->flush();

        self::assertTrue($this->visibility()->isOwner());
        self::assertTrue($this->visibility()->mayChangeSeries($closed));
        $this->signIn($this->chief);
        self::assertFalse($this->visibility()->isOwner());
        self::assertTrue($this->visibility()->mayChangeSeries($closed));
        $this->signIn($this->member);
        self::assertFalse($this->visibility()->mayChangeSeries($closed));
        self::assertFalse($this->visibility()->seesSeries($closed));
        $this->signIn($this->guest);
        self::assertFalse($this->visibility()->mayChangeSeries($closed));
        self::assertFalse($this->visibility()->seesSeries($closed));
    }

    public function testWhoBelongsToWhichSeriesIsKeptUntilItIsForgotten(): void
    {
        $closed = $this->series('Closed weekly', SeriesVisibility::Members);
        $this->em->flush();
        $this->signIn($this->chief);
        self::assertFalse($this->visibility()->seesSeries($closed));

        $this->em->persist(new SeriesMember($closed, $this->chief->id(), false));
        $this->em->flush();
        self::assertFalse($this->visibility()->seesSeries($closed), 'The members are read once.');
        $this->visibility()->forget();
        self::assertTrue($this->visibility()->seesSeries($closed));
    }

    public function testAnAttendeeOnlySeesTheMeetingsOfTheSearch(): void
    {
        $invited = $this->meeting(null, 'Budget review', $this->owner, [$this->member]);
        $this->meeting(null, 'Hiring plan', $this->owner);
        $this->em->flush();

        $this->signIn($this->member);
        self::assertSame([$invited->id()], $this->ids(new MeetingSearch()));
        // What the person sees is narrowed by the search, never widened by it.
        self::assertSame([], $this->ids(new MeetingSearch(text: 'hiring')));
        self::assertSame([], $this->ids(new MeetingSearch(text: 'nothing like it')));
        self::assertSame([$invited->id()], $this->ids(new MeetingSearch(text: 'BUDGET')));
    }

    public function testASearchOrdersPagesAndFiltersTheMeetings(): void
    {
        $first = $this->meeting(null, 'First', $this->owner, [$this->member], '2026-11-02');
        $second = $this->meeting(null, 'Second', $this->owner, [], '2026-11-09');
        $third = $this->meeting(null, 'Third', $this->owner, [$this->member], '2026-11-16');
        $this->em->flush();

        self::assertSame([$third->id(), $second->id(), $first->id()], $this->ids(new MeetingSearch()));
        self::assertSame([$first->id(), $second->id(), $third->id()], $this->ids(new MeetingSearch(oldestFirst: true)));
        self::assertSame([$third->id(), $second->id()], $this->ids(new MeetingSearch(limit: 2)));
        self::assertSame([$first->id()], $this->ids(new MeetingSearch(limit: 2, afterId: $second->id())));
        self::assertSame([], $this->ids(new MeetingSearch(afterId: Meeting::class)));
        self::assertSame([$third->id(), $first->id()], $this->ids(new MeetingSearch(attendeeId: $this->member->id())));
        self::assertSame([$second->id()], $this->ids(new MeetingSearch(from: new \DateTimeImmutable('2026-11-05'), to: new \DateTimeImmutable('2026-11-10'))));
    }

    public function testASearchStartsAtTheNewestWithTheLimitOf500(): void
    {
        $search = new MeetingSearch();

        self::assertSame([500, false, false, ''], [$search->limit, $search->oldestFirst, $search->trash, $search->text]);
    }

    public function testAGuestReadsNothing(): void
    {
        $meeting = $this->meeting(null, 'Budget review', $this->owner);
        $this->em->flush();
        $this->signIn($this->guest);

        foreach ([
            fn (): mixed => $this->meetingReads()->one($meeting->id()),
            fn (): mixed => $this->meetingReads()->view($meeting->id()),
            fn (): mixed => $this->meetingReads()->search(new MeetingSearch()),
        ] as $read) {
            try {
                $read();
                self::fail('A guest read a meeting.');
            } catch (MeetingsRefused $refused) {
                self::assertSame('forbidden', $refused->reason());
            }
        }
    }

    public function testTheAttendeesAreSortedByNameForEachMeeting(): void
    {
        $one = $this->meeting(null, 'One', $this->owner, [$this->member, $this->owner, $this->chief]);
        $two = $this->meeting(null, 'Two', $this->owner, [$this->member, $this->chief]);
        $this->em->flush();

        $attendees = $this->meetingReads()->attendees([$one->id(), $two->id()]);

        self::assertSame(['Charlie Chief', 'Morgan Member', 'Olivia Owner'], array_column($attendees[$one->id()], 'name'));
        self::assertSame(['Charlie Chief', 'Morgan Member'], array_column($attendees[$two->id()], 'name'));
        self::assertSame([$this->chief->id(), $this->member->id()], array_column($attendees[$two->id()], 'id'));
    }

    public function testAMeetingViewShowsItsLinkedRecords(): void
    {
        $meeting = $this->meeting(null, 'Budget review', $this->owner);
        $this->em->persist(new MeetingLink($meeting, 'contact', $this->guest->party()?->id() ?? ''));
        $this->em->flush();

        $view = $this->meetingReads()->view($meeting->id());

        self::assertSame(['contact', $this->guest->party()?->id(), 'Alex Guest'], [$view->links[0]['kind'], $view->links[0]['id'], $view->links[0]['label']]);
        self::assertTrue($view->trashable);
    }

    public function testOnlyActivePeopleOfTheFirmWhoAreStaffAreStaff(): void
    {
        $this->chief->deactivate(new \DateTimeImmutable('2026-11-26 11:00'));
        $this->em->flush();
        $people = self::getContainer()->get(MeetingPeople::class);

        self::assertTrue($people->isStaff($this->owner->id()));
        self::assertTrue($people->isStaff($this->member->id()));
        self::assertFalse($people->isStaff($this->chief->id()), 'Nobody who left.');
        self::assertFalse($people->isStaff($this->guest->id()), 'No guest.');
        self::assertEqualsCanonicalizing([$this->owner->id(), $this->member->id()], array_keys($people->staff()));
        self::assertSame('Charlie Chief', $people->name($this->chief->id()));
        self::assertNull($people->name('nobody'));
    }

    public function testASeriesNotFoundIsNotFoundWithoutAFieldAndInvalidWithOne(): void
    {
        $plain = MeetingsRefused::seriesNotFound();
        $field = MeetingsRefused::seriesNotFound('seriesId');

        self::assertSame(['not_found', RefusalKind::NotFound, null], [$plain->reason(), $plain->kind(), $plain->field()]);
        self::assertSame(['series_not_found', RefusalKind::Invalid, 'seriesId'], [$field->reason(), $field->kind(), $field->field()]);
    }

    public function testTheMeetingListShipsItsViewsFiltersAndQuickPicks(): void
    {
        $lists = self::getContainer()->get(MeetingLists::class);
        $list = $lists->list();

        self::assertSame($list, $lists->list());
        self::assertSame(['series', 'mine', 'when'], $list->pinned);
        self::assertSame(
            [['all', 'meetings.meetings.tab.all', false, 0], ['upcoming', 'meetings.meetings.tab.upcoming', false, 1], ['past', 'meetings.meetings.tab.past', false, 2], ['mine', 'meetings.meetings.tab.mine', false, 3]],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->showCount, $view->position], $list->defaults),
        );
        $conditions = array_map(static fn (DefaultView $view): array => $view->filter->toArray(), $list->defaults);
        self::assertEquals(ViewFilter::all(new Condition('when', 'is', 'upcoming'))->toArray(), $conditions[1]);
        self::assertEquals(ViewFilter::all(new Condition('when', 'is', 'past'))->toArray(), $conditions[2]);
        self::assertEquals(ViewFilter::all(new Condition('mine', 'is', true))->toArray(), $conditions[3]);
        self::assertSame(['is', 'is_not', 'is_any_of', 'is_empty', 'is_not_empty'], $list->field('series')?->operators);
        self::assertSame(['is'], $list->field('when')?->operators);
        self::assertSame(['upcoming', 'past'], $list->field('when')->options);
        self::assertSame(['upcoming' => 'meetings.meetings.when.upcoming', 'past' => 'meetings.meetings.when.past'], $list->field('when')->optionLabels);
    }

    public function testTheDomainSaysWhetherAWriteChangedAnything(): void
    {
        $meeting = new Meeting(null, 'Budget review', new \DateTimeImmutable('2026-11-30'), null, 'Notes', 'user-1', new \DateTimeImmutable('2026-11-26 10:00'));

        self::assertFalse($meeting->writeMinutes('Notes', 1));
        self::assertSame(1, $meeting->version());
        self::assertTrue($meeting->writeMinutes('More notes', 1));
        self::assertSame(2, $meeting->version());
        try {
            $meeting->writeMinutes('Other notes', 1);
            self::fail('Stale minutes were saved.');
        } catch (StaleMinutes $stale) {
            self::assertSame([2, 'The minutes are at version 2 now.'], [$stale->currentVersion, $stale->getMessage()]);
        }

        self::assertTrue($meeting->trash('user-2', new \DateTimeImmutable('2026-11-27')));
        self::assertFalse($meeting->trash('user-3', new \DateTimeImmutable('2026-11-28')));
        self::assertSame('user-2', $meeting->deletedBy());
        self::assertTrue($meeting->restore());
        self::assertFalse($meeting->restore());
        self::assertNull($meeting->deletedAt());

        $series = new MeetingSeries('Production weekly', SeriesVisibility::Members, '', null, 'user-1', new \DateTimeImmutable('2026-11-26 10:00'));
        self::assertTrue($series->archive(new \DateTimeImmutable('2026-11-27')));
        self::assertFalse($series->archive(new \DateTimeImmutable('2026-11-28')));
        self::assertEquals(new \DateTimeImmutable('2026-11-27'), $series->archivedAt());
    }

    public function testTheTrashOfMoreThan30DaysGoesWithEverythingAboutTheMeeting(): void
    {
        $fields = LentFields::lend(self::getContainer(), MeetingExtension::RECORD, ['test_fields_code']);
        $old = $this->meeting(null, 'Old review', $this->owner, [$this->owner, $this->chief, $this->member]);
        $this->em->persist(new MeetingLink($old, 'contact', $this->guest->party()?->id() ?? ''));
        $this->em->persist(new MeetingLink($old, 'project', '01a219bb-1338-7df2-8902-b10f73467797'));
        $kept = $this->meeting(null, 'Kept review', $this->owner, [$this->owner]);
        $this->em->flush();
        $fields->write(MeetingExtension::RECORD, $old->id(), ['test_fields_code' => 'MTG-1']);
        $fields->write(MeetingExtension::RECORD, $kept->id(), ['test_fields_code' => 'MTG-2']);
        $this->meetingWrites()->trash(new TrashMeetingInput($old->id()));
        $purge = self::getContainer()->get(PurgeTrashedMeetings::class);

        self::assertSame(1, $purge->run(new \DateTimeImmutable('2026-12-27 03:00:00')));
        $this->em->clear();

        self::assertNull($this->em->find(Meeting::class, $old->id()));
        self::assertSame([], $this->em->getRepository(MeetingLink::class)->findAll());
        self::assertSame([$kept->id()], array_map(static fn (Attendee $attendee): string => $attendee->meeting()->id(), $this->em->getRepository(Attendee::class)->findAll()));
        self::assertNull($fields->value(MeetingExtension::RECORD, $old->id(), 'test_fields_code'));
        self::assertSame('MTG-2', $fields->value(MeetingExtension::RECORD, $kept->id(), 'test_fields_code'));
    }

    public function testTheDailyPurgeRunsAtThreeInTheMorningOnItsOwn(): void
    {
        $old = $this->meeting(null, 'Old review', $this->owner);
        $this->em->flush();
        $this->meetingWrites()->trash(new TrashMeetingInput($old->id()));
        $job = new PurgeMeetingTrashDaily(self::getContainer()->get(PurgeTrashedMeetings::class), new MockClock('2026-12-27 03:00:00', 'UTC'));

        self::assertSame('meetings-trash-purge', $job->name());
        $job->run(null);
        $this->em->clear();
        self::assertNull($this->em->find(Meeting::class, $old->id()));
    }

    /**
     * @return list<string> the ids the search finds
     */
    private function ids(MeetingSearch $search): array
    {
        return array_map(static fn (Meeting $meeting): string => $meeting->id(), $this->meetingReads()->search($search));
    }

    /**
     * @param list<\Foresi\Identity\Domain\Membership> $members
     */
    private function series(string $name, SeriesVisibility $visibility, array $members = []): MeetingSeries
    {
        $series = new MeetingSeries($name, $visibility, '', null, $this->owner->user()->id(), new \DateTimeImmutable('2026-11-26 10:00'));
        $this->em->persist($series);
        foreach ($members as $member) {
            $this->em->persist(new SeriesMember($series, $member->id(), false));
        }

        return $series;
    }

    /**
     * @param list<\Foresi\Identity\Domain\Membership> $attendees
     */
    private function meeting(?MeetingSeries $series, string $title, \Foresi\Identity\Domain\Membership $creator, array $attendees = [], string $day = '2026-11-30'): Meeting
    {
        $meeting = new Meeting($series, $title, new \DateTimeImmutable($day), null, '', $creator->user()->id(), new \DateTimeImmutable('2026-11-26 10:00'));
        $this->em->persist($meeting);
        foreach ($attendees as $attendee) {
            $this->em->persist(new Attendee($meeting, $attendee->id()));
        }

        return $meeting;
    }
}
