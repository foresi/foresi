<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Application;

use Foresi\Meetings\Application\Api\CreateMeetingInput;
use Foresi\Meetings\Application\Api\CreateSeriesInput;
use Foresi\Meetings\Application\Api\SetMinutesInput;
use Foresi\Meetings\Application\Api\TrashMeetingInput;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Application\PurgeTrashedMeetings;
use Foresi\Meetings\Domain\Attendee;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Tests\Meetings\Support\MeetingsTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * A new meeting of a series takes the series' agenda template, its default
 * attendees and a dated title; the minutes keep a version, so two people
 * writing at once never overwrite each other.
 */
final class MeetingWritesTest extends MeetingsTestCase
{
    private const string AGENDA = "## Numbers\n\n## Hiring\n\n## Next steps\n";

    public function testANewMeetingOfASeriesCopiesItsAgendaTemplateAndDefaultAttendees(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput(
            'Production weekly',
            'members',
            self::AGENDA,
            memberIds: [$this->owner->id(), $this->chief->id(), $this->member->id()],
            defaultAttendeeIds: [$this->owner->id(), $this->chief->id()],
        ));

        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $series->id()));

        self::assertSame(self::AGENDA, $meeting->minutes());
        self::assertSame('Production weekly – 2026-11-30', $meeting->title());
        $attendees = array_column($this->meetingReads()->viewOf($meeting)->attendees, 'id');
        sort($attendees);
        $expected = [$this->owner->id(), $this->chief->id()];
        sort($expected);
        self::assertSame($expected, $attendees);
    }

    public function testMinutesGivenOrALaterTemplateLeaveTheMeetingAsItIs(): void
    {
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', 'members', self::AGENDA, memberIds: [$this->owner->id()]));

        $typed = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $series->id(), title: 'Kick-off', attendeeIds: [], minutes: 'Our own notes'));

        self::assertSame('Our own notes', $typed->minutes());
        self::assertSame('Kick-off', $typed->title());
        self::assertSame([], $this->meetingReads()->viewOf($typed)->attendees);
    }

    public function testMinutesSavedSinceTheVersionReadAreNotOverwritten(): void
    {
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review'));
        $read = $meeting->version();

        $saved = $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), 'Approved the budget.', $read));
        self::assertSame($read + 1, $saved->version());

        try {
            $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), 'Rejected the budget.', $read));
            self::fail('Minutes from a stale version were saved.');
        } catch (MeetingsRefused $refused) {
            self::assertSame(['stale_minutes', 'version', ['current_version' => $read + 1]], [$refused->reason(), $refused->field(), $refused->details()]);
        }
        $this->em->clear();
        self::assertSame('Approved the budget.', $this->meetingReads()->one($meeting->id())->minutes());

        // The same text again changes nothing and keeps the version.
        self::assertSame($read + 1, $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), 'Approved the budget.', $read + 1))->version());
    }

    public function testAMeetingInTheTrashForMoreThan30DaysIsRemovedWithItsAttendees(): void
    {
        $old = $this->meetingWrites()->create(new CreateMeetingInput('2026-10-01', title: 'Old review', attendeeIds: [$this->owner->id()]));
        $recent = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-20', title: 'Recent review', attendeeIds: [$this->owner->id()]));
        $this->meetingWrites()->trash(new TrashMeetingInput($old->id()));
        Clock::set(new MockClock('2026-12-01 10:00:00', 'UTC'));
        $this->meetingWrites()->trash(new TrashMeetingInput($recent->id()));
        $purge = self::getContainer()->get(PurgeTrashedMeetings::class);

        self::assertSame(0, $purge->run(new \DateTimeImmutable('2026-12-26 09:59:59')));
        self::assertSame(1, $purge->run(new \DateTimeImmutable('2026-12-26 10:00:01')));

        $this->em->clear();
        self::assertNull($this->em->find(Meeting::class, $old->id()));
        self::assertInstanceOf(Meeting::class, $this->em->find(Meeting::class, $recent->id()));
        self::assertCount(1, $this->em->getRepository(Attendee::class)->findAll());
    }
}
