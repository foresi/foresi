<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

/**
 * A percent as the person typed it, a decimal string with up to two
 * decimals, read as whole hundredths of a percent (basis points) by the
 * digits of the text, never through a float.
 */
final readonly class Percent
{
    public const string PATTERN = '/^(?:100|[1-9]?\d)(?:\.\d{1,2})?$/D';
    public const int WHOLE = 10_000;

    /**
     * @return int|null the hundredths of a percent ("33.33" is 3333), null when the text is no such percent
     */
    public static function hundredths(string $percent): ?int
    {
        if (1 !== preg_match(self::PATTERN, $percent)) {
            return null;
        }
        [$whole, $decimals] = [...explode('.', $percent), ''];

        $hundredths = (int) $whole * 100 + (int) str_pad($decimals, 2, '0');

        return $hundredths > self::WHOLE ? null : $hundredths;
    }
}
