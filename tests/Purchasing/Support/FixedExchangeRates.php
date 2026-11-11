<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Support;

use App\ExchangeRate\Application\ExchangeRates;
use App\ExchangeRate\Domain\ExchangeRate;
use App\ExchangeRate\Domain\RateNotAvailable;

/**
 * Rates set by the test, published on the day asked for unless told
 * otherwise; any other currency has none. Remembers what was asked.
 */
final class FixedExchangeRates implements ExchangeRates
{
    /** @var array<string, array{int, string, ?string}> */
    private array $rates = [];
    /** @var list<array{string, string}> */
    public array $asked = [];

    public function set(string $currency, string $rate, int $amount = 1, ?string $publishedOn = null): void
    {
        $this->rates[$currency] = [$amount, $rate, $publishedOn];
    }

    public function rateOn(string $currency, \DateTimeImmutable $date): ExchangeRate
    {
        $this->asked[] = [$currency, $date->format('Y-m-d')];
        if (!isset($this->rates[$currency])) {
            throw RateNotAvailable::for($currency, $date);
        }
        [$amount, $rate, $publishedOn] = $this->rates[$currency];

        return new ExchangeRate($currency, $amount, $rate, $date, null === $publishedOn ? $date : new \DateTimeImmutable($publishedOn));
    }
}
