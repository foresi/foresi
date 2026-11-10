<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Application;

use App\ExchangeRate\Domain\ExchangeRate;
use App\ExchangeRate\Domain\RateNotAvailable;

/**
 * The rate of a currency to CZK on a date. The rate published on a business day
 * holds until the next publication, so a weekend or a holiday has the last
 * publication before it, and a date before the day's publication has the
 * previous one. A date not stored yet is fetched and stored; a date in the
 * future has today's rate. CZK is 1 CZK for 1 CZK.
 */
interface ExchangeRates
{
    /**
     * @param string $currency ISO 4217 code, upper case
     *
     * @throws RateNotAvailable the currency is not quoted, or the source could not be reached
     */
    public function rateOn(string $currency, \DateTimeImmutable $date): ExchangeRate;
}
