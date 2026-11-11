<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Application;

/**
 * A currency as a source quotes it: $amount units cost $rate CZK, as a decimal
 * string with a point and without trailing zeros ("14.981", "25").
 */
final readonly class Quote
{
    public string $rate;

    public function __construct(
        public string $currency,
        public int $amount,
        string $rate,
    ) {
        $this->rate = self::normalise($rate);
    }

    /**
     * The decimal string without trailing zeros after the point.
     */
    public static function normalise(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
