<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Application;

use Foresi\Meetings\Application\Api\CreateMeetingInput;
use Foresi\Meetings\Application\Api\CreateSeriesInput;
use Foresi\Meetings\Application\MeetingSearch;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Tests\Meetings\Support\MeetingsTestCase;

/**
 * Who sees a series and its meetings, in the spec's order: the owners, everyone
 * for an organisation series, the members of a members series, the attendees
 * of a meeting, and for a one-off meeting its attendees and its creator.
 */
final class MeetingVisibilityTest extends MeetingsTestCase
{
    public function testAMembersSeriesIsHiddenFromAMemberOfTheFirmWhoIsNotInIt(): void
    {
        $this->signIn($this->chief);
        $series = $this->seriesWrites()->create(new CreateSeriesInput('C-level weekly', 'members', memberIds: [$this->chief->id()]));
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $series->id()));

        // A member of the series sees it and its meetings.
        self::assertSame([$series->id()], $this->seriesIds());
        self::assertSame([$meeting->id()], $this->meetingIds());

        // An owner sees every series.
        $this->signIn($this->owner);
        self::assertSame([$series->id()], $this->seriesIds());
        self::assertSame([$meeting->id()], $this->meetingIds());
        self::assertSame($meeting->id(), $this->meetingReads()->one($meeting->id())->id());

        // A member of the firm who is not in the series sees neither, and an id is not found.
        $this->signIn($this->member);
        self::assertSame([], $this->seriesIds());
        self::assertSame([], $this->meetingIds());
        $this->assertNotFound(fn (): MeetingSeries => $this->seriesReads()->one($series->id()));
        $this->assertNotFound(fn (): Meeting => $this->meetingReads()->one($meeting->id()));
    }

    public function testAnAttendeeSeesTheMeetingButNotTheSeries(): void
    {
        $this->signIn($this->chief);
        $series = $this->seriesWrites()->create(new CreateSeriesInput('C-level weekly', 'members', memberIds: [$this->chief->id()]));
        $invited = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $series->id(), attendeeIds: [$this->chief->id(), $this->member->id()]));
        $this->meetingWrites()->create(new CreateMeetingInput('2026-12-07', $series->id()));

        $this->signIn($this->member);
        self::assertSame([$invited->id()], $this->meetingIds());
        self::assertSame([], $this->seriesIds());
    }

    public function testAnOrganisationSeriesIsSeenByTheWholeFirm(): void
    {
        $this->signIn($this->chief);
        $series = $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', 'organisation', memberIds: [$this->chief->id()]));
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $series->id()));

        $this->signIn($this->member);
        self::assertSame([$series->id()], $this->seriesIds());
        self::assertSame([$meeting->id()], $this->meetingIds());
    }

    public function testAOneOffMeetingIsSeenByItsAttendeesAndItsCreator(): void
    {
        $this->signIn($this->chief);
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', attendeeIds: []));
        self::assertSame([$meeting->id()], $this->meetingIds());

        $this->signIn($this->member);
        self::assertSame([], $this->meetingIds());

        $this->signIn($this->owner);
        self::assertSame([$meeting->id()], $this->meetingIds());
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn($this->guest);
        $this->expectExceptionObject(MeetingsRefused::forbidden());
        $this->seriesReads()->all();
    }

    /**
     * @return list<string>
     */
    private function seriesIds(): array
    {
        return array_map(static fn (MeetingSeries $series): string => $series->id(), $this->seriesReads()->all());
    }

    /**
     * @return list<string>
     */
    private function meetingIds(): array
    {
        return array_map(static fn (Meeting $meeting): string => $meeting->id(), $this->meetingReads()->search(new MeetingSearch()));
    }

    /**
     * @param callable(): mixed $read
     */
    private function assertNotFound(callable $read): void
    {
        try {
            $read();
            self::fail('The read found what the person does not see.');
        } catch (MeetingsRefused $refused) {
            self::assertSame('not_found', $refused->reason());
        }
    }
}
