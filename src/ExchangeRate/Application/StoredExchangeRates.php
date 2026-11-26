<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\Quote;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\ExchangeRate\Domain\ExchangeRate;
use Foresi\ExchangeRate\Domain\RateNotAvailable;
use Psr\Clock\ClockInterface;

/**
 * Reads the stored quotes of the source in use, and fetches the date from it
 * when it is not stored yet. A date already stored that lacks the currency is
 * not fetched again: the source does not quote it. A source priced in another
 * currency than CZK gives the cross rate through its base, rounded half up to
 * six decimals (the scale of the stored rates), for 1, 100 or 1000 units: the
 * fewest for which the rate is at least 1 CZK, as the ČNB quotes.
 */
final readonly class StoredExchangeRates implements ExchangeRates
{
    private const string CZK = 'CZK';
    private const int SCALE = 6;
    /** @var list<int> */
    private const array AMOUNTS = [1, 100, 1000];

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
        if (self::CZK === $currency) {
            return new ExchangeRate(self::CZK, 1, '1', $date, $date);
        }

        $name = $this->source->name();
        $stored = $this->store->find($name, $date, [$currency, self::CZK]);
        if ($stored instanceof PublishedRates) {
            $rate = $this->convert($currency, $date, $stored);
            if ($rate instanceof ExchangeRate) {
                return $rate;
            }
        }
        if (!$this->store->has($name, $date)) {
            $published = $this->fetching->fetch($date);
            if ($published instanceof PublishedRates) {
                $rate = $this->convert($currency, $date, $published);
                if ($rate instanceof ExchangeRate) {
                    return $rate;
                }
            }
        }

        throw RateNotAvailable::for($currency, $date);
    }

    /**
     * The rate of the currency in CZK from quotes priced in their base: as
     * quoted when the base is CZK, else the cross rate through the base.
     */
    private function convert(string $currency, \DateTimeImmutable $date, PublishedRates $rates): ?ExchangeRate
    {
        if (self::CZK === $rates->base) {
            $quote = $rates->quotes[$currency] ?? null;

            return $quote instanceof Quote ? new ExchangeRate($currency, $quote->amount, $quote->rate, $date, $rates->publishedOn) : null;
        }

        $crown = $rates->quotes[self::CZK] ?? null;
        $quote = $rates->quotes[$currency] ?? null;
        if (!$crown instanceof Quote || $currency !== $rates->base && !$quote instanceof Quote) {
            return null;
        }
        // One unit of the currency in the base, then in CZK: the base per unit divided by the base per CZK.
        $inBase = $quote instanceof Quote ? BigRational::of($quote->rate)->dividedBy($quote->amount) : BigRational::one();
        $perUnit = $inBase->multipliedBy($crown->amount)->dividedBy($crown->rate);

        foreach (self::AMOUNTS as $amount) {
            $rate = $perUnit->multipliedBy($amount);
            if ($rate->isGreaterThanOrEqualTo(1) || 1000 === $amount) {
                return new ExchangeRate($currency, $amount, Quote::normalise((string) $rate->toScale(self::SCALE, RoundingMode::HalfUp)), $date, $rates->publishedOn);
            }
        }

        return null;
    }

    /**
     * The date as a day in the zone of the source, no later than today there.
     */
    private function day(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $zone = $this->source->zone();
        $today = $this->clock->now()->setTimezone($zone)->setTime(0, 0);
        $day = new \DateTimeImmutable($date->format('Y-m-d'), $zone);

        return min($day, $today);
    }
}
