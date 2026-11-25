<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * Hours of a budget change as people and agents write them, plus or minus,
 * with at most two decimal places: "8", "-2.5", "1,25". To the nearest minute.
 */
final class BudgetHours
{
    private const string PATTERN = '/^([+-]?)(\d{1,6})(?:[.,](\d{1,2}))?$/D';

    private function __construct()
    {
    }

    /**
     * The minutes, or null for text that is no such number.
     */
    public static function minutes(string $hours): ?int
    {
        if (1 !== preg_match(self::PATTERN, trim($hours), $match)) {
            return null;
        }
        $minutes = self::rounded((int) ($match[2].str_pad($match[3] ?? '', 2, '0')));

        return '-' === $match[1] ? -$minutes : $minutes;
    }

    /**
     * Hundredths of an hour to the nearest minute.
     */
    private static function rounded(int $hundredths): int
    {
        return (int) round($hundredths * 60 / 100);
    }
}
