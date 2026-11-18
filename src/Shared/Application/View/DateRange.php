<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * The days a date value of a filter covers, from the first to the day after the last:
 * an absolute day "2026-11-18", an absolute month {month: "2026-10"}, or a relative
 * rule {relative: "last_month"} resolved against today when the query runs (spec D5),
 * so a view saved as "Last month" stays right after the month turns.
 *
 * Today is midnight in the instance's zone, as InstanceClock::today() gives it. Weeks
 * start on Monday; quarters are those of the calendar year.
 */
final readonly class DateRange
{
    /** The rules that name a period, with no count. */
    public const array PERIODS = ['today', 'yesterday', 'tomorrow', 'this_week', 'last_week', 'this_month', 'last_month', 'next_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year'];
    /** The rules that take a count of days, n from 1 to MAX_DAYS. */
    public const array COUNTED = ['last_n_days', 'next_n_days', 'n_days_ago', 'n_days_from_now'];
    public const int MAX_DAYS = 365;

    private function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    /**
     * Checks a date value without resolving it.
     *
     * @throws InvalidFilter
     */
    public static function check(mixed $value, string $path): void
    {
        if (\is_string($value)) {
            if (!self::day($value) instanceof \DateTimeImmutable) {
                throw new InvalidFilter($path, 'A day is written YYYY-MM-DD.');
            }

            return;
        }
        if (!\is_array($value)) {
            throw new InvalidFilter($path, 'A date is a day, {month} or {relative}.');
        }
        if (isset($value['month'])) {
            if (!\is_string($value['month']) || 1 !== preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/', $value['month']) || 1 !== \count($value)) {
                throw new InvalidFilter($path.'.month', 'A month is written YYYY-MM.');
            }

            return;
        }
        $rule = $value['relative'] ?? null;
        if (\in_array($rule, self::PERIODS, true)) {
            if (1 !== \count($value)) {
                throw new InvalidFilter($path, \sprintf('The rule "%s" takes no count.', $rule));
            }

            return;
        }
        if (\in_array($rule, self::COUNTED, true)) {
            $n = $value['n'] ?? null;
            if (!\is_int($n) || $n < 1 || $n > self::MAX_DAYS || 2 !== \count($value)) {
                throw new InvalidFilter($path.'.n', \sprintf('The rule "%s" takes a count n from 1 to %d.', $rule, self::MAX_DAYS));
            }

            return;
        }
        throw new InvalidFilter($path.'.relative', \sprintf('Unknown relative date "%s".', \is_string($rule) ? $rule : get_debug_type($rule)));
    }

    /**
     * The days a checked value covers on the day given.
     *
     * @throws InvalidFilter
     */
    public static function of(mixed $value, \DateTimeImmutable $today, string $path = 'value'): self
    {
        self::check($value, $path);
        $today = $today->setTime(0, 0);
        if (\is_string($value)) {
            $day = new \DateTimeImmutable($value, $today->getTimezone());

            return new self($day, $day->modify('+1 day'));
        }
        \assert(\is_array($value));
        if (isset($value['month'])) {
            \assert(\is_string($value['month']));
            // "YYYY-MM" parses to the first of its month.
            $start = new \DateTimeImmutable($value['month'], $today->getTimezone());

            return new self($start, $start->modify('first day of next month'));
        }
        $rule = $value['relative'];
        if (\in_array($rule, self::COUNTED, true)) {
            $n = $value['n'];
            \assert(\is_int($n));

            // check() let only the rules listed through, so the last arm is the last of them.
            return match ($rule) {
                // A rolling window that ends with today, as Airtable's "the past number of days".
                'last_n_days' => self::days($today, 1 - $n, 1),
                'next_n_days' => self::days($today, 0, $n),
                'n_days_ago' => self::days($today, -$n, 1 - $n),
                default => self::days($today, $n, $n + 1),
            };
        }

        return match ($rule) {
            'today' => self::days($today, 0, 1),
            'yesterday' => self::days($today, -1, 0),
            'tomorrow' => self::days($today, 1, 2),
            'this_week' => self::week($today, 0),
            'last_week' => self::week($today, -1),
            'this_month' => self::month($today, 0),
            'last_month' => self::month($today, -1),
            'next_month' => self::month($today, 1),
            'this_quarter' => self::quarter($today, 0),
            'last_quarter' => self::quarter($today, -1),
            'this_year' => self::year($today, 0),
            default => self::year($today, -1),
        };
    }

    private static function day(string $value, ?\DateTimeZone $zone = null): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);

        return false !== $day && $day->format('Y-m-d') === $value ? $day : null;
    }

    private static function days(\DateTimeImmutable $today, int $from, int $to): self
    {
        return new self($today->modify(\sprintf('%+d days', $from)), $today->modify(\sprintf('%+d days', $to)));
    }

    private static function week(\DateTimeImmutable $today, int $offset): self
    {
        $monday = $today->modify('monday this week')->modify(\sprintf('%+d weeks', $offset));

        return new self($monday, $monday->modify('+1 week'));
    }

    private static function month(\DateTimeImmutable $today, int $offset): self
    {
        $start = $today->modify('first day of this month')->modify(\sprintf('%+d months', $offset));

        return new self($start, $start->modify('+1 month'));
    }

    private static function quarter(\DateTimeImmutable $today, int $offset): self
    {
        $month = intdiv((int) $today->format('n') - 1, 3) * 3 + 1;
        $start = $today->setDate((int) $today->format('Y'), $month, 1)->modify(\sprintf('%+d months', 3 * $offset));

        return new self($start, $start->modify('+3 months'));
    }

    private static function year(\DateTimeImmutable $today, int $offset): self
    {
        $start = $today->modify('first day of january')->modify(\sprintf('%+d years', $offset));

        return new self($start, $start->modify('+1 year'));
    }
}
