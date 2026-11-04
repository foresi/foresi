<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

/**
 * Reads a value of a plain SQL result the same on SQLite and PostgreSQL, where
 * a flag comes back as 0/1 or as a boolean and a count as an int or a numeric
 * string.
 */
final class Scalars
{
    public static function int(mixed $value): int
    {
        return match (true) {
            \is_int($value) => $value,
            \is_bool($value) => (int) $value,
            \is_string($value) && is_numeric($value) => (int) $value,
            default => throw new \UnexpectedValueException('Not a number: '.get_debug_type($value)),
        };
    }

    public static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Not a string: '.get_debug_type($value));
    }

    public static function optional(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }
}
