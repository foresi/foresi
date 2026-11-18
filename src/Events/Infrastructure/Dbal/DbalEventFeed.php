<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Events\Infrastructure\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Foresi\Events\Application\Query\EventFeed;
use Foresi\Events\Application\Query\EventRow;
use Foresi\Events\Application\Query\EventSlice;
use Foresi\Events\Infrastructure\Relay\PruneEventLogTask;
use Psr\Clock\ClockInterface;

/**
 * Reads the feed from the log. Events older than the log keeps them are left out even if
 * the prune has not run yet, so the feed answers the same before and after it.
 */
final readonly class DbalEventFeed implements EventFeed
{
    public function __construct(
        private Connection $connection,
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
            ->where(EventLogTable::POSITION.' > :after')
            ->andWhere(EventLogTable::OCCURRED_AT.' >= :since')
            ->andWhere(EventLogTable::NAME.' IN (:names)')
            ->orderBy(EventLogTable::POSITION, 'ASC')
            ->setMaxResults($limit + 1)
            ->setParameter('after', $afterPosition ?? 0, Types::BIGINT)
            ->setParameter('since', $this->clock->now()->modify(\sprintf('-%d days', PruneEventLogTask::KEEP_DAYS)), Types::DATETIME_IMMUTABLE)
            ->setParameter('names', $names, ArrayParameterType::STRING)
            ->fetchAllAssociative();

        $platform = $this->connection->getDatabasePlatform();
        $slice = array_map(static fn (array $row): EventRow => EventRowReader::read($row, $platform), \array_slice($rows, 0, $limit));

        return new EventSlice($slice, \count($rows) > $limit);
    }
}
