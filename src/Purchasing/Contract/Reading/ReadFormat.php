<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Reading;

/**
 * Turns the text of a file into the typed values of a reading. A text that does
 * not fit the format gives null, and the reader leaves that one value out; it
 * never guesses. Money is parsed from the decimal text, never through a float.
 */
final class ReadFormat
{
    /**
     * Minor units (two decimals) of a non-negative decimal amount with a point.
     */
    public static function minor(string $text): ?int
    {
        if (1 !== preg_match('/^(\d{1,15})(?:\.(\d+))?$/D', trim($text), $found)) {
            return null;
        }
        $fraction = rtrim($found[2] ?? '', '0');
        if (\strlen($fraction) > 2) {
            return null;
        }

        return (int) $found[1] * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * `Y-m-d` from `YYYY-MM-DD` (with an optional time zone) or `YYYYMMDD`, when it is a real day.
     */
    public static function date(string $text): ?string
    {
        $text = trim($text);
        if (1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:Z|[+-]\d{2}:\d{2})?$/D', $text, $found) || 1 === preg_match('/^(\d{4})(\d{2})(\d{2})$/D', $text, $found)) {
            return checkdate((int) $found[2], (int) $found[3], (int) $found[1]) ? \sprintf('%s-%s-%s', $found[1], $found[2], $found[3]) : null;
        }

        return null;
    }

    /**
     * A VAT rate as a percent without trailing zeros (`21.00` is `21`, `10.50` is `10.5`).
     */
    public static function rate(string $text): ?string
    {
        if (1 !== preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/D', trim($text), $found)) {
            return null;
        }
        $whole = ltrim($found[1], '0');
        $whole = '' === $whole ? '0' : $whole;
        $fraction = rtrim($found[2] ?? '', '0');
        if ((int) $whole > 100 || (100 === (int) $whole && '' !== $fraction)) {
            return null;
        }

        return '' === $fraction ? $whole : $whole.'.'.$fraction;
    }

    /**
     * A VAT id without spaces in capitals: two letters and up to twelve letters or digits.
     */
    public static function vatId(string $text): ?string
    {
        $id = strtoupper((string) preg_replace('/\s+/', '', $text));

        return 1 === preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/D', $id) ? $id : null;
    }

    /**
     * A text of the file as data: control characters become spaces, the spaces
     * are collapsed, the result is capped at $max characters. Null when nothing is left.
     */
    public static function text(string $text, int $max): ?string
    {
        $clean = preg_replace(['/[\x00-\x1F\x7F]+/', '/\s+/u'], ' ', $text);
        if (!\is_string($clean)) {
            return null;
        }
        $clean = mb_substr(trim($clean), 0, $max);

        return '' === $clean ? null : $clean;
    }
}
