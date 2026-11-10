<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Domain;

/**
 * What a currency was worth in CZK on a date: $amount units of the currency cost
 * $rate CZK (the ČNB quotes some currencies per 100 or per 1000, so one unit is
 * rate / amount). The rate is a decimal string, never a float. $validOn is the
 * date asked for; $publishedOn is the day the rate was published, which is
 * earlier on a weekend, a holiday and before the day's publication.
 */
final readonly class ExchangeRate
{
    public function __construct(
        public string $currency,
        public int $amount,
        public string $rate,
        public \DateTimeImmutable $validOn,
        public \DateTimeImmutable $publishedOn,
    ) {
        if ($amount < 1 || 1 !== preg_match('/^[0-9]+(\.[0-9]+)?\z/', $rate)) {
            throw new \InvalidArgumentException('An exchange rate needs a positive amount and a decimal rate.');
        }
    }
}
