<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * The dates of a recurrence. Occurrence n (from 0) is the first issue date
 * plus n × every units, always counted from that anchor and never from the
 * previous date, so a template on the 31st gives 28 February and then 31 March.
 * The arithmetic works on year, month and day integers, so a DST change or a
 * short month never shifts a date.
 */
final class RecurringSchedule
{
    public static function occurrence(Recurrence $r, int $n): \DateTimeImmutable
    {
        if ($n < 0) {
            throw new \InvalidArgumentException(\sprintf('Occurrences count from 0, not %d.', $n));
        }

        $first = $r->firstIssueOn();
        $zone = $first->getTimezone();
        $year = (int) $first->format('Y');
        $month = (int) $first->format('n');
        $day = (int) $first->format('j');
        $steps = $n * $r->every();

        return match ($r->unit()) {
            RecurrenceUnit::Day => self::calendarDay($zone, $year, $month, $day + $steps),
            RecurrenceUnit::Week => self::calendarDay($zone, $year, $month, $day + 7 * $steps),
            RecurrenceUnit::Month => self::inMonth($zone, $year, $month + $steps, $day, $r->onLastDayOfMonth()),
            RecurrenceUnit::Year => self::inMonth($zone, $year + $steps, $month, $day, $r->onLastDayOfMonth()),
        };
    }

    /**
     * Up to $count dates on or after the calendar day of $from, starting with
     * occurrence $issued, and only those the end rule allows.
     *
     * @return list<\DateTimeImmutable>
     */
    public static function next(Recurrence $r, int $issued, \DateTimeImmutable $from, int $count): array
    {
        $fromDay = $from->format('Y-m-d');
        $dates = [];
        for ($n = max($issued, self::estimate($r, $fromDay)); \count($dates) < $count; ++$n) {
            $date = self::occurrence($r, $n);
            if (self::isOver($r, $n, $date)) {
                break;
            }
            if ($date->format('Y-m-d') >= $fromDay) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Whether the template is over once $issued invoices exist and $next is the
     * next occurrence date. An end date includes that day.
     */
    public static function isOver(Recurrence $r, int $issued, \DateTimeImmutable $next): bool
    {
        return match ($r->end()) {
            RecurrenceEnd::Never => false,
            RecurrenceEnd::AfterCount => $issued >= $r->endAfter(),
            RecurrenceEnd::OnDate => $next->format('Y-m-d') > $r->endOn()?->format('Y-m-d'),
        };
    }

    /**
     * @internal the length of a month, read from its first day
     */
    public static function monthLength(int $year, int $month): int
    {
        return (int) new \DateTimeImmutable('@0')->setDate($year, $month, 1)->format('t');
    }

    /**
     * @internal midnight of a calendar day in $zone; a day past the month's end carries into the next months
     */
    public static function calendarDay(\DateTimeZone $zone, int $year, int $month, int $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $zone)->setDate($year, $month, $day)->setTime(0, 0);
    }

    /**
     * @internal $month may run past 12 or below 1 and carries into the year; the day is clamped to the month length
     */
    public static function inMonth(\DateTimeZone $zone, int $year, int $month, int $day, bool $lastDay = false): \DateTimeImmutable
    {
        $index = $year * 12 + $month - 1;
        $year = intdiv($index, 12);
        $month = $index - 12 * $year + 1;
        $length = self::monthLength($year, $month);

        return self::calendarDay($zone, $year, $month, $lastDay ? $length : min($day, $length));
    }

    /**
     * An occurrence index at or before the first one on $fromDay, so a preview
     * far from the anchor does not walk every date in between.
     */
    private static function estimate(Recurrence $r, string $fromDay): int
    {
        $first = $r->firstIssueOn();
        $from = new \DateTimeImmutable($fromDay, $first->getTimezone());
        if ($from <= $first) {
            return 0;
        }

        $months = (int) $from->format('Y') * 12 + (int) $from->format('n') - (int) $first->format('Y') * 12 - (int) $first->format('n');
        $span = match ($r->unit()) {
            RecurrenceUnit::Day => $first->diff($from)->days,
            RecurrenceUnit::Week => intdiv($first->diff($from)->days, 7),
            RecurrenceUnit::Month => $months,
            RecurrenceUnit::Year => intdiv($months, 12),
        };

        return max(0, intdiv($span, $r->every()) - 1);
    }
}
