<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Infrastructure\Schedule;

use App\ExchangeRate\Application\FetchRates;
use App\ExchangeRate\Application\RateSource;
use App\ExchangeRate\Application\RateStore;
use App\Tenancy\Application\Schedule\OperatorTask;
use Psr\Clock\ClockInterface;

/**
 * Fetches today's rates once the source has published them. It ticks every ten
 * minutes and does nothing before the publication time of the day (the ČNB
 * publishes around 14:30, Prague time, the task waits until 14:35) or once the
 * day has rates, so a missed tick, a late publication and a failed fetch are all
 * made up by the next one. A day without a publication has none stored until the
 * source's settled time, so it is asked a few times and then left alone.
 */
final readonly class FetchDailyRates implements OperatorTask
{
    public const string NAME = 'exchange-rates';

    public function __construct(
        private RateSource $source,
        private RateStore $store,
        private FetchRates $fetching,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function every(): string
    {
        return '10 minutes';
    }

    public function run(): void
    {
        $now = $this->clock->now()->setTimezone($this->source->zone());
        if ($now->format('H:i') < $this->source->expectedAt()) {
            return;
        }

        $today = $now->setTime(0, 0);
        if (!$this->store->has($today)) {
            $this->fetching->fetch($today);
        }
    }
}
