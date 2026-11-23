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

    /** The most days between() reads, enough for a daily item over four months. */
    private const int BETWEEN_LIMIT = 130;

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
     * Whether the item falls on exactly this day: a day of its schedule, or the day of a one-off item, that is
     * not skipped and not after its end.
     */
    public function occursOn(PlannedItem $item, \DateTimeImmutable $day): bool
    {
        $zone = $this->clock->timezone();
        $day = $day->setTimezone($zone)->setTime(0, 0);
        $recurrence = $item->recurrence($zone);
        $falls = $recurrence instanceof Recurrence
            ? RecurringSchedule::next($recurrence, 0, $day, 1)[0] ?? null
            : $item->firstOn();

        return $falls?->format('Y-m-d') === $day->format('Y-m-d') && !$item->isSkipped($day);
    }

    /**
     * The days the item falls on from one day to another, both included, oldest first; a skipped day and any
     * after the item's end are not among them.
     *
     * @return list<\DateTimeImmutable> midnight in the instance's zone
     */
    public function between(PlannedItem $item, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $zone = $this->clock->timezone();
        [$first, $last] = [$from->setTimezone($zone)->setTime(0, 0), $to->setTimezone($zone)->setTime(0, 0)];
        $recurrence = $item->recurrence($zone);
        $days = $recurrence instanceof Recurrence ? RecurringSchedule::next($recurrence, 0, $first, self::BETWEEN_LIMIT) : [$item->firstOn()];

        return array_values(array_filter(
            $days,
            fn (\DateTimeImmutable $day): bool => $day->format('Y-m-d') >= $first->format('Y-m-d') && $day->format('Y-m-d') <= $last->format('Y-m-d') && !$item->isSkipped($day),
        ));
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
