<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

/**
 * Months as the options of a select: newest first, named in the locale, and grouped by
 * year when there are more than three years of them, as imports bring the history back.
 */
final class MonthChoices
{
    private const int UNGROUPED = 36;

    /**
     * @param list<string> $months as YYYY-MM
     *
     * @return array<string, string>|array<int, array<string, string>> label by YYYY-MM, or those by year
     */
    public static function of(array $months, string $locale): array
    {
        $months = array_values(array_unique($months));
        rsort($months);
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'LLLL y');
        $choices = [];
        foreach ($months as $month) {
            $choices[$month] = (string) $formatter->format(new \DateTimeImmutable($month.'-01', new \DateTimeZone('UTC')));
        }
        if (\count($choices) <= self::UNGROUPED) {
            return $choices;
        }

        $years = [];
        foreach ($choices as $month => $label) {
            $years[(int) substr($month, 0, 4)][$month] = $label;
        }

        return $years;
    }
}
