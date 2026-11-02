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
 * An offset carries: {day+1} on 31 December is 1, and a token without its own
 * offset follows the nearest finer token that has one, preferring the one
 * before it. So "{month+1}/{year}" on 1 December 2026 gives "1/2027", while
 * "{month} and {month+1}" gives "12 and 1". The offset of {month/year} counts
 * months and moves both parts. Anything else in braces stays as typed.
 */
final class PeriodText
{
    public const array TOKENS = ['{day}', '{month}', '{year}', '{quarter}', '{month/year}'];

    private const string PATTERN = '~\{(day|month/year|month|year|quarter)(?:([+-])(\d{1,4}))?\}~';

    /** How fine each token is: a plain token follows offsets of finer ones only. */
    private const array RANK = ['day' => 0, 'month' => 1, 'month/year' => 1, 'quarter' => 2, 'year' => 3];

    public static function resolve(string $text, \DateTimeImmutable $occurrence): string
    {
        preg_match_all(self::PATTERN, $text, $found, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
        if ([] === $found) {
            return $text;
        }

        $tokens = [];
        foreach ($found as $match) {
            $name = (string) $match[1][0];
            $sign = $match[2][0] ?? null;
            $digits = (int) ($match[3][0] ?? 0);
            $tokens[] = [
                'token' => $name,
                'rank' => self::RANK[$name] ?? 0,
                'offset' => '-' === $sign ? -$digits : $digits,
                'start' => $match[0][1],
                'length' => \strlen((string) $match[0][0]),
            ];
        }

        $resolved = '';
        $position = 0;
        foreach ($tokens as $index => $token) {
            $source = self::source($tokens, $index);
            $date = null === $source ? $occurrence : self::shift($occurrence, $source['token'], $source['offset']);
            $resolved .= substr($text, $position, $token['start'] - $position).self::value($token['token'], $date);
            $position = $token['start'] + $token['length'];
        }

        return $resolved.substr($text, $position);
    }

    /**
     * The token whose offset sets the date of token $index: itself when it has
     * an offset, else the nearest finer token with one, before it first.
     *
     * @param list<array{token: string, rank: int, offset: int, start: int, length: int}> $tokens
     *
     * @return array{token: string, rank: int, offset: int, start: int, length: int}|null
     */
    private static function source(array $tokens, int $index): ?array
    {
        $token = $tokens[$index];
        if (0 !== $token['offset']) {
            return $token;
        }

        $finer = static fn (array $other): bool => 0 !== $other['offset'] && $other['rank'] < $token['rank'];
        for ($i = $index - 1; $i >= 0; --$i) {
            if ($finer($tokens[$i])) {
                return $tokens[$i];
            }
        }
        for ($i = $index + 1, $count = \count($tokens); $i < $count; ++$i) {
            if ($finer($tokens[$i])) {
                return $tokens[$i];
            }
        }

        return null;
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
