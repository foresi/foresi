<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine;

use App\Purchasing\Application\EvidenceNumbers;
use App\Purchasing\Application\EvidenceNumbersExhausted;
use App\Purchasing\Domain\EvidenceNumberFormat;
use App\Purchasing\Domain\EvidenceSeries;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * One atomic statement per number, the same on SQLite and PostgreSQL, as the
 * counter of the sales series does (ADR 0013). The row lock of the upsert is the
 * whole of the concurrency control: a second transaction waits at this
 * statement until the first commits, then counts on from its value. The
 * statement is read to the end, which is what finishes a RETURNING statement on
 * SQLite.
 */
final readonly class DoctrineEvidenceNumbers implements EvidenceNumbers
{
    private const string NEXT = <<<'SQL'
        INSERT INTO evidence_counter (id, tenant_id, evidence_series_id, period, last_value)
        VALUES (:id, :tenant, :series, :period, 1)
        ON CONFLICT (evidence_series_id, period) DO UPDATE
        SET last_value = evidence_counter.last_value + 1
        RETURNING last_value
        SQL;

    private const string LAST = 'SELECT last_value FROM evidence_counter WHERE tenant_id = :tenant AND evidence_series_id = :series AND period = :period';

    /** PostgreSQL refuses a value above its integer column; SQLite stores it, so it is checked here. */
    private const string NUMERIC_OUT_OF_RANGE = '22003';

    public function __construct(private Connection $connection, private TenantContext $context)
    {
    }

    public function peek(EvidenceSeries $series, \DateTimeImmutable $date): string
    {
        $format = $series->format();
        $last = $this->connection->fetchOne(self::LAST, ['tenant' => $this->context->current()->toString(), 'series' => $series->id(), 'period' => $format->period($date)]);

        return $format->render($date, (false === $last ? 0 : $this->sequence($last)) + 1);
    }

    public function next(EvidenceSeries $series, \DateTimeImmutable $date): string
    {
        $format = $series->format();
        try {
            $given = $this->connection->fetchFirstColumn(self::NEXT, ['id' => Id::generate(), 'tenant' => $this->context->current()->toString(), 'series' => $series->id(), 'period' => $format->period($date)]);
        } catch (DriverException $failure) {
            throw self::NUMERIC_OUT_OF_RANGE === $failure->getSQLState() ? new EvidenceNumbersExhausted('The series has no number left.', previous: $failure) : $failure;
        }

        $sequence = $this->sequence($given[0] ?? null);
        if ($sequence > EvidenceNumberFormat::MAX_SEQUENCE) {
            // The caller's rollback takes this value back out of the counter.
            throw new EvidenceNumbersExhausted('The series has no number left.');
        }

        return $format->render($date, $sequence);
    }

    private function sequence(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \LogicException('A counter holds a number.');
    }
}
