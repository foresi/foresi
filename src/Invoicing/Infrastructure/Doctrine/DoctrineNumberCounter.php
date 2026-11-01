<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Domain\Series;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * One atomic statement per change, the same on SQLite and PostgreSQL. The row lock
 * of the upsert is the whole of the concurrency control: a second transaction
 * waits at this statement until the first commits, then counts on from its value,
 * so nothing is read before it and no number can be taken twice. The statements
 * are read to the end, which is what finishes a RETURNING statement on SQLite.
 */
final readonly class DoctrineNumberCounter implements NumberCounter
{
    private const string NEXT = <<<'SQL'
        INSERT INTO series_counter (id, tenant_id, series_id, period, last_value)
        VALUES (:id, :tenant, :series, :period, :start)
        ON CONFLICT (series_id, period) DO UPDATE
        SET last_value = CASE WHEN series_counter.last_value + 1 < :start THEN :start ELSE series_counter.last_value + 1 END
        RETURNING last_value
        SQL;

    private const string ADVANCE = <<<'SQL'
        INSERT INTO series_counter (id, tenant_id, series_id, period, last_value)
        VALUES (:id, :tenant, :series, :period, :start)
        ON CONFLICT (series_id, period) DO UPDATE
        SET last_value = CASE WHEN series_counter.last_value < :start THEN :start ELSE series_counter.last_value END
        SQL;

    private const string LAST = 'SELECT last_value FROM series_counter WHERE tenant_id = :tenant AND series_id = :series AND period = :period';

    public function __construct(private Connection $connection, private TenantContext $context)
    {
    }

    public function peek(Series $series, \DateTimeImmutable $date): string
    {
        $format = $series->format();
        $period = $format->period($date);

        return $format->render($date, max($this->last($series, $period) + 1, $series->startFor($period)));
    }

    public function next(Series $series, \DateTimeImmutable $date): string
    {
        $format = $series->format();
        $period = $format->period($date);
        $given = $this->connection->fetchFirstColumn(self::NEXT, $this->values($series, $period, $series->startFor($period)), ['start' => ParameterType::INTEGER]);

        return $format->render($date, $this->sequence($given[0]));
    }

    public function advanceTo(Series $series, \DateTimeImmutable $date, int $sequence): void
    {
        if ($sequence < 1) {
            return;
        }

        $this->connection->executeStatement(self::ADVANCE, $this->values($series, $series->format()->period($date), $sequence), ['start' => ParameterType::INTEGER]);
    }

    public function last(Series $series, string $period): int
    {
        $last = $this->connection->fetchOne(self::LAST, ['tenant' => $this->context->current()->toString(), 'series' => $series->id(), 'period' => $period]);

        return false === $last ? 0 : $this->sequence($last);
    }

    private function sequence(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \LogicException('A counter holds a number.');
    }

    /**
     * @return array<string, int|string>
     */
    private function values(Series $series, string $period, int $start): array
    {
        return ['id' => Id::generate(), 'tenant' => $this->context->current()->toString(), 'series' => $series->id(), 'period' => $period, 'start' => $start];
    }
}
