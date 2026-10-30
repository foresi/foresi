<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** Reads a date written as text; ISO dates are always understood. */
final class DateParser
{
    /** The date as the start of that day in UTC, or null when the text is not a real date. */
    public static function parse(string $text, DateOrder $order): ?\DateTimeImmutable
    {
        if (1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?$/', $text, $iso)) {
            return self::build((int) $iso[1], (int) $iso[2], (int) $iso[3]);
        }
        if (1 !== preg_match('/^(\d{1,4})\s*[.\/-]\s*(\d{1,2})\s*[.\/-]\s*(\d{1,4})$/', $text, $parts)) {
            return null;
        }
        [$year, $month, $day] = match ($order) {
            DateOrder::DayMonthYear => [$parts[3], $parts[2], $parts[1]],
            DateOrder::MonthDayYear => [$parts[3], $parts[1], $parts[2]],
            DateOrder::YearMonthDay => [$parts[1], $parts[2], $parts[3]],
        };
        if (4 !== \strlen($year)) {
            return null;
        }

        return self::build((int) $year, (int) $month, (int) $day);
    }

    private static function build(int $year, int $month, int $day): ?\DateTimeImmutable
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->setDate($year, $month, $day)->setTime(0, 0);
    }
}
