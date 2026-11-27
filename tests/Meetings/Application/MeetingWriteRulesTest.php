<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Application;

use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Meetings\Application\Api\CreateMeetingInput;
use Foresi\Meetings\Application\Api\CreateSeriesInput;
use Foresi\Meetings\Application\Api\RestoreMeetingInput;
use Foresi\Meetings\Application\Api\SetMinutesInput;
use Foresi\Meetings\Application\Api\TrashMeetingInput;
use Foresi\Meetings\Application\Api\UpdateMeetingInput;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Application\MeetingWrites;
use Foresi\Meetings\Contract\Field\MeetingExtension;
use Foresi\Meetings\Domain\Attendee;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Meetings\Support\MeetingsTestCase;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\WorkflowTemplate;

/**
 * What a meeting accepts: the limits and formats of its fields, who may write it, which
 * attendees and links are allowed, and the events each write records.
 */
final class MeetingWriteRulesTest extends MeetingsTestCase
{
    public function testAGuestChangesNothing(): void
    {
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review'));
        $this->signIn($this->guest);

        foreach ([
            fn (): mixed => $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Hidden')),
            fn (): mixed => $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), title: 'Changed')),
            fn (): mixed => $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), 'Notes', 1)),
            fn (): mixed => $this->meetingWrites()->trash(new TrashMeetingInput($meeting->id())),
            fn (): mixed => $this->meetingWrites()->restore(new RestoreMeetingInput($meeting->id())),
        ] as $write) {
            self::assertSame('forbidden', $this->refusal($write)->reason());
        }
    }

    public function testASeriesTheMemberCannotSeeCannotBeUsed(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('C-level weekly', 'members', memberIds: [$this->owner->id()]));
        $this->signIn($this->member);

        foreach ([$series->id(), Id::generate(), 'not-an-id'] as $id) {
            $refused = $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $id)));
            self::assertSame(['series_not_found', 'seriesId'], [$refused->reason(), $refused->field()]);
        }
    }

    public function testAnArchivedSeriesTakesNoNewMeeting(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Old weekly', 'organisation'));
        $this->seriesWrites()->archive(new \Foresi\Meetings\Application\Api\ArchiveSeriesInput($series->id()));

        self::assertSame('series_archived', $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $series->id())))->reason());
    }

    public function testAMeetingWithoutATitleTakesTheDefaultOfItsSeriesOrIsRefused(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', 'organisation'));

        self::assertSame('Production weekly – 2026-11-30', $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $series->id(), title: '   '))->title());
        self::assertSame('Production weekly – 2026-12-07', $this->meetingWrites()->create(new CreateMeetingInput('2026-12-07', $series->id()))->title());
        foreach ([null, '', '  '] as $title) {
            $refused = $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: $title)));
            self::assertSame(['title_required', 'title', 'A meeting without a series needs a title.'], [$refused->reason(), $refused->field(), $refused->getMessage()]);
        }
    }

    public function testTheDefaultTitleIsCutByCharactersNotBytes(): void
    {
        $name = str_repeat('é', MeetingSeries::MAX_NAME);
        $series = $this->seriesWrites()->create(new CreateSeriesInput($name, 'organisation'));
        $day = new \DateTimeImmutable('2026-11-30');

        self::assertSame($name.' – 2026-11-30', MeetingWrites::defaultTitle($series, $day));
        self::assertSame($name.' – 2026-11-30', $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $series->id()))->title());
    }

    public function testTheTitleIsTrimmedAndKeptWithin200Characters(): void
    {
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: '  Budget review  '));
        self::assertSame('Budget review', $meeting->title());

        $long = str_repeat('é', Meeting::MAX_TITLE);
        self::assertSame($long, $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: $long))->title());
        $refused = $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: $long.'x')));
        self::assertSame(['title_too_long', 'title', 'The title has at most 200 characters.'], [$refused->reason(), $refused->field(), $refused->getMessage()]);

        self::assertSame('Padded', $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), title: ' Padded '))->title());
        $refused = $this->refusal(fn (): Meeting => $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), title: '   ')));
        self::assertSame(['title_required', 'title', 'The meeting needs a title.'], [$refused->reason(), $refused->field(), $refused->getMessage()]);
        self::assertSame('title_too_long', $this->refusal(fn (): Meeting => $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), title: $long.'x')))->reason());
    }

    public function testTheMinutesAreKeptWithin200000Characters(): void
    {
        $full = str_repeat('é', Meeting::MAX_MINUTES);
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', minutes: $full));
        self::assertSame(Meeting::MAX_MINUTES, mb_strlen($meeting->minutes()));

        $refused = $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Too long', minutes: $full.'x')));
        self::assertSame(['minutes_too_long', 'minutes', 'The minutes have at most 200000 characters.'], [$refused->reason(), $refused->field(), $refused->getMessage()]);
        self::assertSame('minutes_too_long', $this->refusal(fn (): Meeting => $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), $full.'x', 1)))->reason());
    }

    public function testTheDayIsARealDate(): void
    {
        foreach (['2026-02-30', '2026-13-01', 'garbage', '', '2026-11-3', 'x2026-11-30', '2026-11-30x', "2026-11-30\n", '30-11-2026'] as $day) {
            $refused = $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new CreateMeetingInput($day, title: 'Budget review')));
            self::assertSame(['held_on_invalid', 'heldOn', 'The day is a date, YYYY-MM-DD.'], [$refused->reason(), $refused->field(), $refused->getMessage()], $day);
        }
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2028-02-29', title: 'Leap day'));
        self::assertSame('2028-02-29', $meeting->heldOn()->format('Y-m-d'));
        self::assertSame('held_on_invalid', $this->refusal(fn (): Meeting => $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), heldOn: '2026-02-30')))->reason());
        self::assertSame('2026-12-24', $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), heldOn: '2026-12-24'))->heldOn()->format('Y-m-d'));
    }

    public function testTheStartTimeIsHoursAndMinutesOrNone(): void
    {
        foreach ([null, '', '   '] as $none) {
            self::assertNull($this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', startsAt: $none))->startsAt());
        }
        foreach (['00:00', '09:30', '19:59', '23:59'] as $time) {
            self::assertSame($time, $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', startsAt: $time))->startsAt());
        }
        self::assertSame('09:30', $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', startsAt: ' 09:30 '))->startsAt());
        foreach (['24:00', '9:30', '09:60', '09:30:00', 'x09:30', '0930', 'noon'] as $time) {
            $refused = $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', startsAt: $time)));
            self::assertSame(['starts_at_invalid', 'startsAt', 'The time is HH:MM, such as 09:30.'], [$refused->reason(), $refused->field(), $refused->getMessage()], $time);
        }
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', startsAt: '09:30'));
        self::assertNull($this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), startsAt: ' '))->startsAt());
        self::assertSame('14:15', $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), startsAt: '14:15'))->startsAt());
    }

    public function testOnlyPeopleOfTheFirmAttendOnceEach(): void
    {
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', attendeeIds: [$this->chief->id(), $this->member->id(), $this->chief->id()]));
        $ids = array_column($this->meetingReads()->viewOf($meeting)->attendees, 'id');
        sort($ids);
        $expected = [$this->chief->id(), $this->member->id()];
        sort($expected);
        self::assertSame($expected, $ids);

        foreach ([[$this->guest->id()], [Id::generate()], [42], [null], [$this->chief->id(), $this->guest->id()]] as $attendees) {
            $refused = $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new \ReflectionClass(CreateMeetingInput::class)->newInstanceArgs(['heldOn' => '2026-11-30', 'title' => 'Budget review', 'attendeeIds' => $attendees])));
            self::assertSame(['attendee_unknown', 'attendeeIds'], [$refused->reason(), $refused->field()]);
        }
        self::assertSame('attendee_unknown', $this->refusal(fn (): Meeting => $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), attendeeIds: [$this->guest->id()])))->reason());
    }

    public function testAnAttendeeWhoLeftTheFirmStaysButNobodyNewMay(): void
    {
        // Built from its rows, so that the firm's people are first read after the member left.
        $meeting = new Meeting(null, 'Budget review', new \DateTimeImmutable('2026-11-30'), null, '', $this->owner->user()->id(), new \DateTimeImmutable('2026-11-26 10:00'));
        $this->em->persist($meeting);
        $this->em->persist(new Attendee($meeting, $this->chief->id()));
        $this->em->persist(new Attendee($meeting, $this->member->id()));
        $this->chief->deactivate(new \DateTimeImmutable('2026-11-26 11:00'));
        $this->em->flush();
        $this->signIn($this->owner);

        // The person who left stays, the other is replaced by the owner.
        $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), attendeeIds: [$this->chief->id(), $this->owner->id()]));
        $this->em->clear();
        $ids = array_column($this->meetingReads()->viewOf($this->meetingReads()->one($meeting->id()))->attendees, 'id');
        sort($ids);
        $expected = [$this->chief->id(), $this->owner->id()];
        sort($expected);
        self::assertSame($expected, $ids);

        // Nobody who left is a new attendee.
        self::assertSame('attendee_unknown', $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Other', attendeeIds: [$this->chief->id()])))->reason());
    }

    public function testAMeetingChangesItsAttendeesAndKeepsTheRest(): void
    {
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', attendeeIds: [$this->chief->id(), $this->member->id()]));

        $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), attendeeIds: [$this->member->id(), $this->owner->id()]));
        $this->em->clear();
        $ids = array_column($this->meetingReads()->viewOf($this->meetingReads()->one($meeting->id()))->attendees, 'id');
        sort($ids);
        $expected = [$this->member->id(), $this->owner->id()];
        sort($expected);
        self::assertSame($expected, $ids);

        // An update that leaves the attendees out keeps them.
        $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), title: 'Renamed'));
        $this->em->clear();
        self::assertCount(2, $this->meetingReads()->viewOf($this->meetingReads()->one($meeting->id()))->attendees);
    }

    public function testLinksAreRecordsThePersonMayLinkOnceEach(): void
    {
        $first = $this->project('Website');
        $second = $this->project('Shop');
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', links: [['kind' => 'project', 'id' => $first->id()], ['kind' => 'project', 'id' => $second->id()], ['kind' => 'project', 'id' => $first->id()]]));
        self::assertSame([$first->id(), $second->id()], $this->linkedIds($meeting));

        foreach ([[['kind' => 'project']], [['id' => $first->id()]], ['project'], [['kind' => 'invoice', 'id' => $first->id()]], [['kind' => 'project', 'id' => 42]]] as $links) {
            $refused = $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new \ReflectionClass(CreateMeetingInput::class)->newInstanceArgs(['heldOn' => '2026-11-30', 'title' => 'Other', 'links' => $links])));
            self::assertSame(['link_invalid', 'links'], [$refused->reason(), $refused->field()]);
            self::assertStringContainsString('contact, project', $refused->getMessage());
        }
        $refused = $this->refusal(fn (): Meeting => $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Other', links: [['kind' => 'project', 'id' => Id::generate()]])));
        self::assertSame(['link_not_found', 'links'], [$refused->reason(), $refused->field()]);
    }

    public function testUpdatingLinksKeepsAKeptRecordThatCannotBeLinkedAnyMore(): void
    {
        $kept = $this->project('Website');
        $dropped = $this->project('Shop');
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', links: [['kind' => 'project', 'id' => $kept->id()], ['kind' => 'project', 'id' => $dropped->id()]]));
        $kept->archive(new \DateTimeImmutable('2026-11-26 11:00'));
        $dropped->archive(new \DateTimeImmutable('2026-11-26 11:00'));
        $fresh = $this->project('Blog');
        $this->em->flush();

        // The archived project stays linked, the other is let go, a new one is added.
        $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), links: [['kind' => 'project', 'id' => $kept->id()], ['kind' => 'project', 'id' => $fresh->id()]]));
        $this->em->clear();
        self::assertSame([$kept->id(), $fresh->id()], $this->linkedIds($this->meetingReads()->one($meeting->id())));

        // An archived project is not a new link.
        self::assertSame('link_not_found', $this->refusal(fn (): Meeting => $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), links: [['kind' => 'project', 'id' => $dropped->id()]])))->reason());

        // An update that leaves the links out keeps them; an empty list removes them.
        $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), title: 'Renamed'));
        $this->em->clear();
        self::assertCount(2, $this->linkedIds($this->meetingReads()->one($meeting->id())));
        $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), links: []));
        $this->em->clear();
        self::assertSame([], $this->linkedIds($this->meetingReads()->one($meeting->id())));
    }

    public function testACustomFieldIsRequiredWhenAMeetingIsCreatedNotWhenItIsChanged(): void
    {
        $fields = LentFields::lend(self::getContainer(), MeetingExtension::RECORD, ['test_fields_code']);

        try {
            $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review'), []);
            self::fail('A new meeting needs its required field.');
        } catch (FieldRefused $refused) {
            self::assertSame(['test_fields_code', FieldRefused::REQUIRED], [$refused->key, $refused->translationKey]);
        }
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review'), ['test_fields_code' => 'MTG-1']);
        self::assertSame('MTG-1', $fields->value(MeetingExtension::RECORD, $meeting->id(), 'test_fields_code'));

        // A change of something else asks for nothing; a change of the field is written.
        $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), title: 'Renamed'), []);
        $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id()), ['test_fields_code' => 'MTG-2']);
        self::assertSame('MTG-2', $fields->value(MeetingExtension::RECORD, $meeting->id(), 'test_fields_code'));
        self::assertSame([['meeting_id' => $meeting->id(), 'series_id' => null, 'custom_fields' => ['test_fields_code']]], \array_slice($this->events('meetings.meeting.updated'), 1));
    }

    public function testEachWriteRecordsItsEvent(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', 'organisation'));
        $inSeries = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $series->id()));
        $oneOff = $this->meetingWrites()->create(new CreateMeetingInput('2026-12-01', title: 'Budget review'));
        self::assertSame([['meeting_id' => $inSeries->id(), 'series_id' => $series->id()], ['meeting_id' => $oneOff->id(), 'series_id' => null]], $this->events('meetings.meeting.created'));

        $this->meetingWrites()->setMinutes(new SetMinutesInput($oneOff->id(), 'Approved.', 1));
        $this->meetingWrites()->setMinutes(new SetMinutesInput($oneOff->id(), 'Approved.', 2));
        self::assertSame([['meeting_id' => $oneOff->id(), 'series_id' => null, 'version' => 2]], $this->events('meetings.meeting.minutes_changed'));

        $this->meetingWrites()->trash(new TrashMeetingInput($oneOff->id()));
        $this->meetingWrites()->trash(new TrashMeetingInput($oneOff->id()));
        $this->meetingWrites()->trash(new TrashMeetingInput($inSeries->id()));
        self::assertSame($this->owner->user()->id(), $this->meetingReads()->one($oneOff->id())->deletedBy());
        self::assertSame([['meeting_id' => $oneOff->id(), 'series_id' => null], ['meeting_id' => $inSeries->id(), 'series_id' => $series->id()]], $this->events('meetings.meeting.trashed'));

        $this->meetingWrites()->restore(new RestoreMeetingInput($oneOff->id()));
        $this->meetingWrites()->restore(new RestoreMeetingInput($oneOff->id()));
        $this->meetingWrites()->restore(new RestoreMeetingInput($inSeries->id()));
        self::assertSame([['meeting_id' => $oneOff->id(), 'series_id' => null], ['meeting_id' => $inSeries->id(), 'series_id' => $series->id()]], $this->events('meetings.meeting.restored'));
        self::assertNull($this->meetingReads()->one($oneOff->id())->deletedBy());
    }

    public function testAMeetingInTheTrashOrOutOfSightCannotBeChanged(): void
    {
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review'));
        $this->meetingWrites()->trash(new TrashMeetingInput($meeting->id()));

        self::assertSame('trashed', $this->refusal(fn (): Meeting => $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), title: 'Changed')))->reason());
        self::assertSame('trashed', $this->refusal(fn (): Meeting => $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), 'Notes', 1)))->reason());

        $this->signIn($this->member);
        foreach ([$meeting->id(), Id::generate(), 'not-an-id'] as $id) {
            self::assertSame('not_found', $this->refusal(fn (): Meeting => $this->meetingWrites()->update(new UpdateMeetingInput($id, title: 'Changed')))->reason());
        }
        // Whoever sees a meeting but did not create it and owns nothing may not trash it.
        $this->signIn($this->owner);
        $shared = $this->meetingWrites()->create(new CreateMeetingInput('2026-12-01', title: 'Shared', attendeeIds: [$this->member->id()]));
        $this->signIn($this->member);
        self::assertSame('not_trasher', $this->refusal(fn (): Meeting => $this->meetingWrites()->trash(new TrashMeetingInput($shared->id())))->reason());
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

    private function project(string $name): Project
    {
        $project = new Project($name, null, WorkflowTemplate::Simple, $this->owner->user()->id(), new \DateTimeImmutable('2026-11-26 09:00'));
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /**
     * @return list<string> the ids of the records the meeting is linked to, in order
     */
    private function linkedIds(Meeting $meeting): array
    {
        return array_column($this->meetingReads()->viewOf($meeting)->links, 'id');
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
