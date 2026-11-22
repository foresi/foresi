<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Application;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\Api\ArchiveActivityTypeInput;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;

/**
 * "My planned": from today at midnight unless a day is given, thirty days
 * unless an end is given, at most 400; the types, without the archived ones
 * unless asked for them.
 */
final class ActivityReadsTest extends ActivitiesTestCase
{
    public function testMyPlannedStartsAtMidnightOfToday(): void
    {
        $this->planned('Yesterday evening', '2026-11-21T23:30:00+00:00');
        $this->planned('Just before midnight', '2026-11-21T23:59:30+00:00');
        $midnight = $this->planned('At midnight', '2026-11-22T00:00:00+00:00');
        $later = $this->planned('This morning', '2026-11-22T09:00:00+00:00');
        $beyond = $this->planned('In a month', '2026-12-25T09:00:00+00:00');

        self::assertSame([$midnight->id(), $later->id()], $this->ids($this->reads()->myPlanned(null, null)), 'Today from midnight, thirty days.');
        self::assertSame([$midnight->id(), $later->id(), $beyond->id()], $this->ids($this->reads()->myPlanned(null, '2027-01-01')));
    }

    public function testMyPlannedFromTheDayGiven(): void
    {
        $this->planned('This morning', '2026-11-22T09:00:00+00:00');
        $soon = $this->planned('Next week', '2026-11-30T09:00:00+00:00');

        self::assertSame([$soon->id()], $this->ids($this->reads()->myPlanned('2026-11-25', null)));
    }

    public function testTheWindowIsAtMost400Days(): void
    {
        $reads = $this->reads();

        self::assertSame([], $reads->myPlanned('2026-11-22', '2027-12-27'), '400 days are allowed.');
        foreach ([['2026-11-22', '2027-12-28'], ['2026-11-22', '2026-11-22'], ['2026-11-22', '2026-11-21']] as [$from, $to]) {
            try {
                $reads->myPlanned($from, $to);
                self::fail('Refused.');
            } catch (ActivitiesRefused $refused) {
                self::assertSame('window_invalid', $refused->reason());
                self::assertSame('to', $refused->field());
            }
        }
    }

    public function testArchivedTypesAreLeftOutUnlessAskedFor(): void
    {
        $call = $this->type('call');
        $meeting = $this->type('meeting');
        $this->types()->archive(new ArchiveActivityTypeInput($call->id()));

        self::assertSame([$meeting->id()], $this->typeIds($this->reads()->types()));
        $both = [$call->id(), $meeting->id()];
        sort($both);
        self::assertSame($both, $this->typeIds($this->reads()->types(true)));
    }

    public function testATimelineNamesARecordByItsWholeKind(): void
    {
        foreach (['contact x', 'contact!', 'a'.str_repeat('b', 40)] as $kind) {
            try {
                $this->reads()->timeline($kind, $this->contactId);
                self::fail($kind.' is refused.');
            } catch (ActivitiesRefused $refused) {
                self::assertSame('link_invalid', $refused->reason(), $kind);
            }
        }
    }

    /**
     * @param list<Activity> $activities
     *
     * @return list<string>
     */
    private function ids(array $activities): array
    {
        return array_map(static fn (Activity $activity): string => $activity->id(), $activities);
    }

    /**
     * @param list<ActivityType> $types
     *
     * @return list<string>
     */
    private function typeIds(array $types): array
    {
        $ids = array_map(static fn (ActivityType $type): string => $type->id(), $types);
        sort($ids);

        return $ids;
    }
}
