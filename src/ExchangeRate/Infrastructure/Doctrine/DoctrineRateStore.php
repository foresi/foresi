<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Infrastructure\Doctrine;

use App\ExchangeRate\Application\PublishedRates;
use App\ExchangeRate\Application\Quote;
use App\ExchangeRate\Application\RateStore;
use App\ExchangeRate\Domain\ExchangeRate;
use Doctrine\DBAL\Connection;

/**
 * Plain statements on the global exchange_rate table, the same on SQLite and
 * PostgreSQL. Saving is one insert per quote that leaves a stored rate as it is,
 * so two fetches of the same day at once are no harm.
 */
final readonly class DoctrineRateStore implements RateStore
{
    private const string INSERT = <<<'SQL'
        INSERT INTO exchange_rate (rate_date, currency, amount, rate, published_on, source)
        VALUES (:date, :currency, :amount, :rate, :published, :source)
        ON CONFLICT (rate_date, currency) DO NOTHING
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function find(string $currency, \DateTimeImmutable $date): ?ExchangeRate
    {
        $row = $this->connection->fetchAssociative(
            'SELECT amount, CAST(rate AS VARCHAR(40)) AS rate, published_on FROM exchange_rate WHERE rate_date = :date AND currency = :currency',
            ['date' => $date->format('Y-m-d'), 'currency' => $currency],
        );
        if (false === $row) {
            return null;
        }
        \assert(is_numeric($row['amount']) && \is_string($row['rate']) && \is_string($row['published_on']));

        return new ExchangeRate(
            $currency,
            (int) $row['amount'],
            $this->trimmed($row['rate']),
            $date,
            new \DateTimeImmutable(substr($row['published_on'], 0, 10)),
        );
    }

    public function has(\DateTimeImmutable $date): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM exchange_rate WHERE rate_date = :date', ['date' => $date->format('Y-m-d')]);
    }

    public function save(\DateTimeImmutable $validOn, PublishedRates $published, string $source): void
    {
        $this->connection->transactional(function () use ($validOn, $published, $source): void {
            foreach ($published->quotes as $quote) {
                $this->connection->executeStatement(self::INSERT, [
                    'date' => $validOn->format('Y-m-d'),
                    'currency' => $quote->currency,
                    'amount' => $quote->amount,
                    'rate' => $quote->rate,
                    'published' => $published->publishedOn->format('Y-m-d'),
                    'source' => $source,
                ]);
            }
        });
    }

    /**
     * PostgreSQL pads a decimal to its scale ("14.981000"); a rate is kept without the padding.
     */
    private function trimmed(string $rate): string
    {
        return Quote::normalise($rate);
    }
}
