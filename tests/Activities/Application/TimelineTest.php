<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Application;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\Api\CompleteActivityInput;
use Foresi\Activities\Application\Api\RestoreActivityInput;
use Foresi\Activities\Application\Api\TrashActivityInput;
use Foresi\Activities\Domain\Activity;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;

/**
 * A record's timeline, as HubSpot, Pipedrive and Twenty show it: what is
 * planned on top, the soonest first, then what is done, the latest first. The
 * trash is left out.
 */
final class TimelineTest extends ActivitiesTestCase
{
    public function testPlannedOnTopThenDoneNewestFirst(): void
    {
        $later = $this->planned('Proposal review', '2026-12-01T10:00:00+01:00');
        $task = $this->planned('Send the NDA', '2026-11-24', type: 'task');
        $sooner = $this->planned('Kick-off meeting', '2026-11-25T09:00:00+01:00');
        $old = $this->logged('First call', '2026-11-01T10:00:00+01:00');
        $recent = $this->logged('Site visit', '2026-11-20T10:00:00+01:00');
        $trashed = $this->logged('Wrong client', '2026-11-21T10:00:00+01:00');
        $this->activities()->trash(new TrashActivityInput($trashed->id()));

        self::assertSame([$task->id(), $sooner->id(), $later->id(), $recent->id(), $old->id()], $this->ids($this->reads()->timeline('contact', $this->contactId)));

        $this->activities()->complete(new CompleteActivityInput($sooner->id()));
        $this->activities()->restore(new RestoreActivityInput($trashed->id()));
        self::assertSame([$task->id(), $later->id(), $sooner->id(), $trashed->id(), $recent->id(), $old->id()], $this->ids($this->reads()->timeline('contact', $this->contactId)), 'Done now is the latest done.');
    }

    public function testPlannedAtTheSameMomentGoByTheirIds(): void
    {
        $ids = [];
        for ($at = 0; $at < 6; ++$at) {
            $ids[] = $this->planned('Kick-off meeting '.$at, '2026-11-25T10:00:00+01:00')->id();
        }
        sort($ids);

        self::assertSame($ids, $this->ids($this->reads()->timeline('contact', $this->contactId)));
    }

    public function testADayIsDueAtTheStartOfItsDayNotWhenItWasWritten(): void
    {
        $late = $this->planned('Send the NDA', '2026-11-30', type: 'task');
        $soon = $this->planned('Kick-off meeting', '2026-11-25T09:00:00+01:00');

        self::assertSame([$soon->id(), $late->id()], $this->ids($this->reads()->timeline('contact', $this->contactId)));
    }

    public function testSameActivityOnDealAndContact(): void
    {
        $dealId = Id::generate();
        $this->deals->add($dealId, 'Audit');
        $activity = $this->planned('Kick-off meeting', links: [['kind' => 'contact', 'id' => $this->contactId], ['kind' => 'deal', 'id' => $dealId], ['kind' => 'deal', 'id' => $dealId]]);

        self::assertSame([$activity->id()], $this->ids($this->reads()->timeline('contact', $this->contactId)));
        self::assertSame([$activity->id()], $this->ids($this->reads()->timeline('deal', $dealId)));
        self::assertCount(2, $this->store()->links($activity), 'A link given twice counts once.');
        self::assertSame([], $this->reads()->timeline('deal', Id::generate()));
    }

    public function testARecordIsNamedByItsKindAndId(): void
    {
        foreach ([['Contact', $this->contactId], ['contact', 'nope']] as [$kind, $id]) {
            try {
                $this->reads()->timeline($kind, $id);
                self::fail('Refused.');
            } catch (ActivitiesRefused $refused) {
                self::assertSame('link_invalid', $refused->reason());
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
}
