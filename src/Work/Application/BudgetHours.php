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
        $hundredths = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '', 2, '0');
        $minutes = (int) round($hundredths * 60 / 100);

        return '-' === $match[1] ? -$minutes : $minutes;
    }
}
