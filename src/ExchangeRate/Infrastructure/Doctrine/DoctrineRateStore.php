<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Foresi\ExchangeRate\Application\RateStore;
use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\Quote;

/**
 * Plain statements on the global exchange_rate table, the same on SQLite and
 * PostgreSQL. Saving is one insert per quote that leaves a stored quote as it
 * is, so two fetches of the same day at once are no harm.
 */
final readonly class DoctrineRateStore implements RateStore
{
    private const string INSERT = <<<'SQL'
        INSERT INTO exchange_rate (rate_date, currency, source, amount, rate, base, published_on)
        VALUES (:date, :currency, :source, :amount, :rate, :base, :published)
        ON CONFLICT (rate_date, currency, source) DO NOTHING
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function find(string $source, \DateTimeImmutable $date, array $currencies): ?PublishedRates
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT currency, amount, CAST(rate AS VARCHAR(40)) AS rate, base, published_on FROM exchange_rate WHERE rate_date = :date AND source = :source AND currency IN (:currencies)',
            ['date' => $date->format('Y-m-d'), 'source' => $source, 'currencies' => $currencies],
            ['currencies' => ArrayParameterType::STRING],
        );
        if ([] === $rows) {
            return null;
        }

        $quotes = [];
        $base = $published = null;
        foreach ($rows as $row) {
            \assert(\is_string($row['currency']) && is_numeric($row['amount']) && \is_string($row['rate']) && \is_string($row['base']) && \is_string($row['published_on']));
            $currency = trim($row['currency']);
            $quotes[$currency] = new Quote($currency, (int) $row['amount'], $this->trimmed($row['rate']));
            $base = trim($row['base']);
            $published = substr($row['published_on'], 0, 10);
        }

        return new PublishedRates(new \DateTimeImmutable($published), $quotes, $base);
    }

    public function has(string $source, \DateTimeImmutable $date): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM exchange_rate WHERE rate_date = :date AND source = :source', ['date' => $date->format('Y-m-d'), 'source' => $source]);
    }

    public function save(\DateTimeImmutable $validOn, PublishedRates $published, string $source): void
    {
        $this->connection->transactional(function () use ($validOn, $published, $source): void {
            foreach ($published->quotes as $quote) {
                $this->connection->executeStatement(self::INSERT, [
                    'date' => $validOn->format('Y-m-d'),
                    'currency' => $quote->currency,
                    'source' => $source,
                    'amount' => $quote->amount,
                    'rate' => $quote->rate,
                    'base' => $published->base,
                    'published' => $published->publishedOn->format('Y-m-d'),
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
