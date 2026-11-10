<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Application;

use App\ExchangeRate\Domain\ExchangeRate;
use App\ExchangeRate\Domain\RateNotAvailable;
use Psr\Clock\ClockInterface;

/**
 * Reads the stored rates, and fetches the date from the source when it is not
 * stored yet. A date already stored that lacks the currency is not fetched
 * again: the source does not quote it.
 */
final readonly class StoredExchangeRates implements ExchangeRates
{
    public function __construct(
        private RateStore $store,
        private FetchRates $fetching,
        private RateSource $source,
        private ClockInterface $clock,
    ) {
    }

    public function rateOn(string $currency, \DateTimeImmutable $date): ExchangeRate
    {
        $date = $this->day($date);
        if ('CZK' === $currency) {
            return new ExchangeRate('CZK', 1, '1', $date, $date);
        }

        $rate = $this->store->find($currency, $date);
        if ($rate instanceof ExchangeRate) {
            return $rate;
        }
        if (!$this->store->has($date)) {
            $published = $this->fetching->fetch($date);
            $quote = $published?->quotes[$currency] ?? null;
            if ($published instanceof PublishedRates && $quote instanceof Quote) {
                return new ExchangeRate($currency, $quote->amount, $quote->rate, $date, $published->publishedOn);
            }
        }

        throw RateNotAvailable::for($currency, $date);
    }

    /**
     * The date as a day in the zone of the source, no later than today there.
     */
    private function day(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $zone = $this->source->zone();
        $today = $this->clock->now()->setTimezone($zone)->setTime(0, 0);
        $day = new \DateTimeImmutable($date->format('Y-m-d'), $zone);

        return $day > $today ? $today : $day;
    }
}
