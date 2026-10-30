<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Source;

/**
 * The text a spreadsheet cell shows: numbers without exponent or trailing
 * zeros, dates as ISO dates.
 */
final class CellText
{
    private function __construct()
    {
    }

    public static function of(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_string($value) => $value,
            \is_bool($value) => $value ? '1' : '0',
            \is_int($value) => (string) $value,
            \is_float($value) => self::number($value),
            $value instanceof \DateTimeInterface => $value->format('00:00:00' === $value->format('H:i:s') ? 'Y-m-d' : 'Y-m-d H:i:s'),
            $value instanceof \DateInterval => $value->format('%H:%I:%S'),
            default => '',
        };
    }

    private static function number(float $value): string
    {
        if (!is_finite($value)) {
            return (string) $value;
        }

        // The shortest text that reads back as the same float, e.g. "1.0E+25".
        $text = (string) $value;
        if (1 !== preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:E([+-]?\d+))?$/i', $text, $parts)) {
            return $text;
        }

        $digits = $parts[2].($parts[3] ?? '');
        $point = \strlen($parts[2]) + (int) ($parts[4] ?? 0);

        if ($point <= 0) {
            $plain = '0.'.str_repeat('0', -$point).$digits;
        } elseif ($point >= \strlen($digits)) {
            $plain = $digits.str_repeat('0', $point - \strlen($digits));
        } else {
            $plain = substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        if (str_contains($plain, '.')) {
            $plain = rtrim(rtrim($plain, '0'), '.');
        }
        $plain = ltrim($plain, '0');
        if ('' === $plain || '.' === $plain[0]) {
            $plain = '0'.$plain;
        }

        return '0' === $plain ? '0' : $parts[1].$plain;
    }
}
