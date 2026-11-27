<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\View;

/**
 * The days a calendar of due dates shows (plan 37.5): a month as whole weeks from Monday to
 * Sunday, or one week, around the day it is opened on. Without the weekend the weeks hold
 * Monday to Friday only; the range asked for is the whole weeks still, so what falls on a
 * weekend can be counted.
 */
final readonly class CalendarPeriod
{
    public const string MONTH = 'month';
    public const string WEEK = 'week';
    public const array PERIODS = [self::MONTH, self::WEEK];

    private function __construct(
        public string $period,
        /** The day the calendar is opened on; a month starts on its first day. */
        public \DateTimeImmutable $anchor,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public bool $weekends,
    ) {
    }

    /**
     * The period an address names: month or week (month for anything else), around the day
     * of on= (Y-m-d; today for anything else).
     */
    public static function of(string $period, string $on, \DateTimeImmutable $today, bool $weekends = true): self
    {
        $period = \in_array($period, self::PERIODS, true) ? $period : self::MONTH;
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        $anchor = false !== $day && $day->format('Y-m-d') === $on ? $day : $today->setTime(0, 0);
        if (self::MONTH === $period) {
            $anchor = $anchor->modify('first day of this month');
            $from = self::monday($anchor);
            $to = self::monday($anchor->modify('last day of this month'))->modify('+6 days');
        } else {
            $from = self::monday($anchor);
            $to = $from->modify('+6 days');
        }

        return new self($period, $anchor, $from, $to, $weekends);
    }

    /**
     * @return list<list<\DateTimeImmutable>> the weeks, each its days shown in order
     */
    public function weeks(): array
    {
        $weeks = [];
        foreach (new \DatePeriod($this->from, new \DateInterval('P7D'), $this->to) as $monday) {
            $days = [];
            for ($at = 0; $at < ($this->weekends ? 7 : 5); ++$at) {
                $days[] = $monday->modify(\sprintf('+%d days', $at));
            }
            $weeks[] = $days;
        }

        return $weeks;
    }

    /**
     * Whether a day is one of the period's own: a day of another month in the first and the
     * last week of a month is shown dimmed.
     */
    public function owns(\DateTimeImmutable $day): bool
    {
        return self::WEEK === $this->period || $day->format('Y-m') === $this->anchor->format('Y-m');
    }

    public static function isWeekend(\DateTimeImmutable $day): bool
    {
        return \in_array($day->format('N'), ['6', '7'], true);
    }

    /**
     * The day the previous period opens on.
     */
    public function previous(): string
    {
        return (self::MONTH === $this->period ? $this->anchor->modify('first day of previous month') : $this->from->modify('-7 days'))->format('Y-m-d');
    }

    /**
     * The day the next period opens on.
     */
    public function next(): string
    {
        return (self::MONTH === $this->period ? $this->anchor->modify('first day of next month') : $this->from->modify('+7 days'))->format('Y-m-d');
    }

    private static function monday(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->modify('monday this week');
    }
}
