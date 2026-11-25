<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Contract;

/**
 * A currency as a source quotes it: $amount units cost $rate units of the base
 * of the publication (PublishedRates::$base), as a decimal string with a point
 * and without trailing zeros ("14.981", "25"). The ČNB quotes "1 EUR costs
 * 24.335 CZK"; a source that quotes the other way round, as the ECB does with
 * "1 EUR is 1.0935 USD", writes it as "10935 USD cost 10000 EUR", which is the
 * same fact with nothing rounded.
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
