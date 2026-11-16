<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Dbal;

use App\Events\Application\Query\EventFeed;
use App\Events\Application\Query\EventRow;
use App\Events\Application\Query\EventSlice;
use App\Events\Infrastructure\Relay\PruneEventLogTask;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Reads the feed from the log. DBAL has no tenant filter, so the organisation is
 * part of the query. Events older than the log keeps them are left out even if
 * the prune has not run yet, so the feed answers the same before and after it.
 */
final readonly class DbalEventFeed implements EventFeed
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenant,
        private ClockInterface $clock,
    ) {
    }

    public function slice(?int $afterPosition, array $names, int $limit): EventSlice
    {
        if ([] === $names) {
            return new EventSlice([], false);
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $rows = $this->connection->createQueryBuilder()
            ->select(...EventRowReader::COLUMNS)
            ->from(EventLogTable::LOG)
            ->where(EventLogTable::TENANT_ID.' = :tenant')
            ->andWhere(EventLogTable::POSITION.' > :after')
            ->andWhere(EventLogTable::OCCURRED_AT.' >= :since')
            ->andWhere(EventLogTable::NAME.' IN (:names)')
            ->orderBy(EventLogTable::POSITION, 'ASC')
            ->setMaxResults($limit + 1)
            ->setParameter('tenant', $this->tenant->current()->toString())
            ->setParameter('after', $afterPosition ?? 0, Types::BIGINT)
            ->setParameter('since', $this->clock->now()->modify(\sprintf('-%d days', PruneEventLogTask::KEEP_DAYS)), Types::DATETIME_IMMUTABLE)
            ->setParameter('names', $names, ArrayParameterType::STRING)
            ->fetchAllAssociative();

        $platform = $this->connection->getDatabasePlatform();
        $slice = array_map(static fn (array $row): EventRow => EventRowReader::read($row, $platform), \array_slice($rows, 0, $limit));

        return new EventSlice($slice, \count($rows) > $limit);
    }
}
