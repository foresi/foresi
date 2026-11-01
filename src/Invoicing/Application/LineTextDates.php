<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Whether a line names a date, which a copy of the document would repeat as if it were
 * still true: `10/2026`, `10.2026`, `31.10.2026`, `2026-10`, or a month by its name in
 * the language of the document, in both the forms a calendar has.
 */
final class LineTextDates
{
    private const string NUMERIC = '~(?<![\d.,/-])(?:\d{1,2}[./-]\s?)?(?:0?[1-9]|1[0-2])[./-]\s?(?:19|20)\d{2}(?!\d)|(?<![\d.,/A-Za-z-])(?:19|20)\d{2}-(?:0[1-9]|1[0-2])(?!\d)~';
    private const array MONTH_PATTERNS = ['LLLL', 'MMMM'];

    public static function found(string $text, string $locale): bool
    {
        if (1 === preg_match(self::NUMERIC, $text)) {
            return true;
        }

        return 1 === preg_match('~(?<!\p{L})(?:'.implode('|', self::months($locale)).')(?!\p{L})~iu', $text);
    }

    /**
     * @return list<string>
     */
    private static function months(string $locale): array
    {
        $months = [];
        foreach (self::MONTH_PATTERNS as $pattern) {
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, $pattern);
            for ($month = 1; $month <= 12; ++$month) {
                $months[] = (string) $formatter->format(new \DateTimeImmutable(\sprintf('2026-%02d-15', $month), new \DateTimeZone('UTC')));
            }
        }

        return array_values(array_unique($months));
    }
}
