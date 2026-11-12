<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Relay;

use App\Events\Infrastructure\Dbal\EventLogTable;
use App\Tenancy\Application\Schedule\OperatorTask;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Deletes relayed events older than thirty days, with the record of their
 * consumptions, once a day. A row with no position yet is never deleted: its
 * listeners have not been handed it.
 */
final readonly class PruneEventLogTask implements OperatorTask
{
    public const string NAME = 'event-log-prune';
    public const int KEEP_DAYS = 30;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function every(): string
    {
        return '1 day';
    }

    public function run(): void
    {
        $before = $this->clock->now()->modify(\sprintf('-%d days', self::KEEP_DAYS));
        $expired = \sprintf(
            'SELECT %s FROM %s WHERE %s IS NOT NULL AND %s < :before',
            EventLogTable::ID,
            EventLogTable::LOG,
            EventLogTable::POSITION,
            EventLogTable::OCCURRED_AT,
        );
        $this->connection->transactional(function (Connection $connection) use ($expired, $before): void {
            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s IN (%s)', EventLogTable::CONSUMPTION, EventLogTable::EVENT_ID, $expired),
                ['before' => $before],
                ['before' => Types::DATETIME_IMMUTABLE],
            );
            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s IS NOT NULL AND %s < :before', EventLogTable::LOG, EventLogTable::POSITION, EventLogTable::OCCURRED_AT),
                ['before' => $before],
                ['before' => Types::DATETIME_IMMUTABLE],
            );
        });
    }
}
