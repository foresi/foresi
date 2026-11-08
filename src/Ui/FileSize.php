<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui;

/**
 * The size of a file in words a person reads at a glance, as a file list
 * shows it: bytes below a kilobyte, else one decimal place in the number
 * format of the language ("1,2 MB" in Czech, "1.2 MB" in English). A
 * kilobyte is 1 024 bytes, as in the upload limit of 50 MB.
 */
final class FileSize
{
    private const array UNITS = ['kB', 'MB', 'GB', 'TB'];

    public static function format(int $bytes, string $locale): string
    {
        if ($bytes < 1024) {
            return max(0, $bytes)."\u{a0}B";
        }
        $size = (float) $bytes;
        $unit = -1;
        while ($size >= 1024 && $unit < \count(self::UNITS) - 1) {
            $size /= 1024;
            ++$unit;
        }
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 1);

        return $formatter->format($size)."\u{a0}".self::UNITS[$unit];
    }
}
