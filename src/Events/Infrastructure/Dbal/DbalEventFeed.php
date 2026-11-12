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
use Doctrine\DBAL\Types\Type;
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
            ->select(
                EventLogTable::ID,
                EventLogTable::NAME,
                EventLogTable::VERSION,
                EventLogTable::POSITION,
                EventLogTable::OCCURRED_AT,
                EventLogTable::SUBJECT_TYPE,
                EventLogTable::SUBJECT_ID,
                EventLogTable::DATA,
                EventLogTable::CORRELATION_ID,
                EventLogTable::ACTOR_KIND,
            )
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

        $slice = array_map($this->row(...), \array_slice($rows, 0, $limit));

        return new EventSlice($slice, \count($rows) > $limit);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function row(array $row): EventRow
    {
        $platform = $this->connection->getDatabasePlatform();
        $facts = Type::getType(Types::JSON)->convertToPHPValue($row[EventLogTable::DATA], $platform);
        $occurred = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($row[EventLogTable::OCCURRED_AT], $platform);
        if (!$occurred instanceof \DateTimeImmutable || !\is_array($facts)) {
            throw new \UnexpectedValueException('The event log holds a row of an unexpected shape.');
        }
        $correlation = $row[EventLogTable::CORRELATION_ID];
        $named = [];
        foreach ($facts as $name => $fact) {
            $named[(string) $name] = $fact;
        }

        return new EventRow(
            $this->text($row[EventLogTable::ID]),
            $this->text($row[EventLogTable::NAME]),
            $this->number($row[EventLogTable::VERSION]),
            $this->number($row[EventLogTable::POSITION]),
            $occurred,
            $this->text($row[EventLogTable::SUBJECT_TYPE]),
            $this->text($row[EventLogTable::SUBJECT_ID]),
            $named,
            null === $correlation ? null : $this->text($correlation),
            $this->text($row[EventLogTable::ACTOR_KIND]),
        );
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('The event log holds a column of an unexpected type.');
    }

    private function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('The event log holds a column of an unexpected type.');
    }
}
