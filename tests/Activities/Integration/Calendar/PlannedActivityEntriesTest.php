<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Integration\Calendar;

use Foresi\Activities\Application\Api\CompleteActivityInput;
use Foresi\Activities\Application\Api\CreateActivityInput;
use Foresi\Activities\Application\Api\TrashActivityInput;
use Foresi\Activities\Integration\Calendar\PlannedActivityEntries;
use Foresi\Calendar\Contract\CalendarEntry;
use Foresi\Calendar\Application\CalendarFeedReader;
use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Calendar\Domain\FeedToken;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;

/**
 * The planned activities in their owner's calendar feed: only the owner's,
 * planned, not done and not in the trash; a day is an all-day entry; the title
 * is "type: subject" and nothing more.
 */
final class PlannedActivityEntriesTest extends ActivitiesTestCase
{
    public function testOnlyOwnersPlannedNotDoneNotTrashed(): void
    {
        $member = $this->person('member@example.com', Role::Member);
        $this->em->flush();
        $mine = $this->planned('Kick-off meeting');
        $this->logged('First call', '2026-11-20T10:00:00+01:00');
        $trashed = $this->planned('Wrong meeting');
        $this->activities()->trash(new TrashActivityInput($trashed->id()));
        $theirs = $this->activities()->create(new CreateActivityInput($this->type('call')->id(), 'Call the client', plannedAt: '2026-11-26T09:00:00+01:00', ownerUserId: $member->user()->id()));
        $outside = $this->planned('Next year', '2028-01-10T10:00:00+01:00');

        self::assertSame([$mine->id()], $this->ids($this->owner->user()->id()));
        self::assertSame([$theirs->id()], $this->ids($member->user()->id()));
        self::assertNotContains($outside->id(), $this->ids($this->owner->user()->id()));
        self::assertSame(PlannedActivityEntries::KEY, $this->source()->key());
        $feed = new CalendarFeed($this->owner->user()->id(), 'Work', FeedToken::hash('token'), $this->clock->now());
        self::assertStringContainsString('SUMMARY:Meeting: Kick-off meeting', self::getContainer()->get(CalendarFeedReader::class)->calendar($feed), 'The calendar feeds read it.');
    }

    public function testDateOnlyIsAllDay(): void
    {
        $task = $this->planned('Send the NDA', '2026-11-24', type: 'task');
        $meeting = $this->planned('Kick-off meeting', '2026-11-25T10:00:00+01:00');

        [$day, $timed] = $this->entries($this->owner->user()->id());

        self::assertSame([true, '2026-11-24', null], [$day->allDay, $day->start->format('Y-m-d'), $day->end]);
        self::assertSame([false, '2026-11-25T09:00:00+00:00', '2026-11-25T10:00:00+00:00'], [$timed->allDay, $timed->start->format(\DATE_ATOM), $timed->end?->format(\DATE_ATOM)]);
        self::assertSame('activities.planned-'.$task->id().'@localhost', $day->uid);
        self::assertSame('http://localhost/activities/'.$meeting->id(), $timed->url);
        self::assertEquals($meeting->updatedAt(), $timed->lastModified);
    }

    public function testDoneItemDropsOut(): void
    {
        $meeting = $this->planned('Kick-off meeting');
        self::assertCount(1, $this->entries($this->owner->user()->id()));

        $this->activities()->complete(new CompleteActivityInput($meeting->id()));

        self::assertSame([], $this->entries($this->owner->user()->id()));
    }

    public function testTitleHasNoBody(): void
    {
        $this->activities()->create(new CreateActivityInput($this->type('meeting')->id(), 'Kick-off meeting', 'The client wants an audit of the roofs; budget 80k.', plannedAt: '2026-11-25T10:00:00+01:00', links: [['kind' => 'contact', 'id' => $this->contactId]]));

        [$entry] = $this->entries($this->owner->user()->id());

        self::assertSame('Meeting: Kick-off meeting', $entry->title);
        self::assertStringNotContainsString('Example Client', $entry->title);
    }

    private function source(): PlannedActivityEntries
    {
        return self::getContainer()->get(PlannedActivityEntries::class);
    }

    /**
     * @return list<CalendarEntry>
     */
    private function entries(string $userId): array
    {
        return array_values([...$this->source()->entriesFor($userId, new \DateTimeImmutable('2026-10-23 00:00 UTC'), new \DateTimeImmutable('2027-11-22 00:00 UTC'))]);
    }

    /**
     * @return list<string>
     */
    private function ids(string $userId): array
    {
        return array_map(static fn (CalendarEntry $entry): string => substr($entry->uid, \strlen(PlannedActivityEntries::KEY) + 1, 36), $this->entries($userId));
    }
}
