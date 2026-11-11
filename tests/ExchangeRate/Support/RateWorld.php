<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\ExchangeRate\Support;

use App\ExchangeRate\Application\FetchRates;
use App\ExchangeRate\Application\StoredExchangeRates;
use App\ExchangeRate\Infrastructure\Doctrine\DoctrineRateStore;
use App\ExchangeRate\Infrastructure\Schedule\FetchDailyRates;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Clock\MockClock;

/**
 * The exchange rates wired by hand: a scratch SQLite database, a source that
 * publishes on Monday to Friday and answers like the ČNB, and a clock in Prague.
 * Friday 6 and Tuesday 10 November 2026 are published; Monday 9 is not (a holiday
 * in this world), nor is the weekend.
 */
final readonly class RateWorld
{
    public Connection $db;
    public FakeRateSource $source;
    public MockClock $clock;
    public DoctrineRateStore $store;
    public FetchRates $fetching;
    public StoredExchangeRates $rates;
    public FetchDailyRates $daily;

    public function __construct(string $now)
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE exchange_rate (rate_date DATE NOT NULL, currency CHAR(3) NOT NULL, amount INTEGER NOT NULL, rate NUMERIC(18, 6) NOT NULL, published_on DATE NOT NULL, source VARCHAR(16) NOT NULL, PRIMARY KEY (rate_date, currency))');
        $this->clock = new MockClock($now, 'Europe/Prague');
        $this->source = new FakeRateSource($this->clock);
        $this->store = new DoctrineRateStore($this->db);
        $this->fetching = new FetchRates($this->source, $this->store, $this->clock);
        $this->rates = new StoredExchangeRates($this->store, $this->fetching, $this->source, $this->clock);
        $this->daily = new FetchDailyRates($this->source, $this->store, $this->fetching, $this->clock);
    }

    public function rows(): int
    {
        $count = $this->db->fetchOne('SELECT COUNT(*) FROM exchange_rate');
        \assert(is_numeric($count));

        return (int) $count;
    }
}
