<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Shared\Domain\Schedule\RecurringSchedule;

/**
 * Which occurrence of a recurring planned item a day belongs to: the one whose
 * period (PlannedFulfilment::period) holds the day, counted in the instance's
 * zone. The occurrence may fall before or after the day inside its period. A
 * skipped occurrence and any after the item's end hold nothing.
 */
final readonly class PlannedOccurrences
{
    /** Occurrences read from the search start; the period holding a day is among the first few. */
    private const int WINDOW = 6;

    public function __construct(private InstanceClock $clock)
    {
    }

    /**
     * The day of the occurrence, midnight in the instance's zone; null for a one-off item,
     * when no occurrence holds the day, and when the one that does is skipped.
     */
    public function holding(PlannedItem $item, \DateTimeImmutable $day): ?\DateTimeImmutable
    {
        $zone = $this->clock->timezone();
        $recurrence = $item->recurrence($zone);
        if (!$recurrence instanceof Recurrence) {
            return null;
        }
        // An instant is the day it is in the instance's zone; a date made in that zone stays the same day.
        $day = $day->setTimezone($zone)->setTime(0, 0);
        $key = $day->format('Y-m-d');
        foreach (RecurringSchedule::next($recurrence, 0, $this->searchFrom($recurrence, $day), self::WINDOW) as $occurrence) {
            [$first, $last] = PlannedFulfilment::period($item, $occurrence);
            if ($first->format('Y-m-d') > $key) {
                return null;
            }
            if ($last->format('Y-m-d') >= $key) {
                return $item->isSkipped($occurrence) ? null : $occurrence;
            }
        }

        return null;
    }

    /**
     * A day no later than the first occurrence whose period can hold $day: a whole cycle of the item before it.
     */
    private function searchFrom(Recurrence $recurrence, \DateTimeImmutable $day): \DateTimeImmutable
    {
        $every = $recurrence->every();

        return match ($recurrence->unit()) {
            RecurrenceUnit::Day => $day->modify(\sprintf('-%d days', $every)),
            RecurrenceUnit::Week => $day->modify('monday this week')->modify(\sprintf('-%d weeks', $every)),
            RecurrenceUnit::Month => $day->modify('first day of this month')->modify(\sprintf('-%d months', $every)),
            RecurrenceUnit::Year => new \DateTimeImmutable(\sprintf('%d-01-01', (int) $day->format('Y') - $every), $day->getTimezone()),
        };
    }
}
