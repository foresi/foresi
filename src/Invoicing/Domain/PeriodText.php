<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * Period variables in the texts of a recurring invoice, resolved against the
 * occurrence date: {day}, {month}, {year}, {quarter} and {month/year}, each with
 * an optional offset such as {month+1} or {year-1}. Numbers have no leading
 * zero, so {month/year} gives "3/2027".
 *
 * Tokens are independent, as in iDoklad: each moves the occurrence date by its
 * own offset only and shows its own part of the result, and no offset carries
 * into another token. So "{month+1}/{year}" on 1 December 2026 gives "1/2026",
 * {day+1} on 31 December gives 1 while a plain {month} next to it stays 12, and
 * "Services {month}/{year}, due {day+14}.{month}.{year}" on 20 January 2027
 * gives "Services 1/2027, due 3.1.2027". Write {year+1} to move the year too.
 * The one exception is {month/year}, a single token whose offset counts months
 * and moves both parts: {month/year+1} on 1 December 2026 gives "1/2027".
 * Anything else in braces stays as typed.
 */
final class PeriodText
{
    public const array TOKENS = ['{day}', '{month}', '{year}', '{quarter}', '{month/year}'];

    private const string PATTERN = '~\{(day|month/year|month|year|quarter)(?:([+-])(\d{1,4}))?\}~';

    public static function resolve(string $text, \DateTimeImmutable $occurrence): string
    {
        preg_match_all(self::PATTERN, $text, $found, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
        if ([] === $found) {
            return $text;
        }

        $resolved = '';
        $position = 0;
        foreach ($found as $match) {
            $token = (string) $match[1][0];
            $digits = (int) ($match[3][0] ?? 0);
            $offset = '-' === ($match[2][0] ?? null) ? -$digits : $digits;
            $start = $match[0][1];
            $resolved .= substr($text, $position, $start - $position).self::value($token, self::shift($occurrence, $token, $offset));
            $position = $start + \strlen((string) $match[0][0]);
        }

        return $resolved.substr($text, $position);
    }

    private static function shift(\DateTimeImmutable $date, string $token, int $offset): \DateTimeImmutable
    {
        $zone = $date->getTimezone();
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');

        return match ($token) {
            'day' => RecurringSchedule::calendarDay($zone, $year, $month, $day + $offset),
            'quarter' => RecurringSchedule::inMonth($zone, $year, $month + 3 * $offset, $day),
            'year' => RecurringSchedule::inMonth($zone, $year + $offset, $month, $day),
            default => RecurringSchedule::inMonth($zone, $year, $month + $offset, $day),
        };
    }

    private static function value(string $token, \DateTimeImmutable $date): string
    {
        return match ($token) {
            'day' => $date->format('j'),
            'month' => $date->format('n'),
            'year' => $date->format('Y'),
            'quarter' => (string) (intdiv((int) $date->format('n') - 1, 3) + 1),
            default => $date->format('n/Y'),
        };
    }
}
