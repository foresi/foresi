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
 * listeners have not been handed it. Nor is the row of the highest position,
 * which keeps the positions rising across a quiet month.
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
        $this->connection->transactional(function (Connection $connection) use ($before): void {
            // The row of the highest position stays, however old: the relay numbers on from it,
            // so an empty log would start the positions at 1 again.
            $highest = $connection->fetchOne(\sprintf('SELECT MAX(%s) FROM %s', EventLogTable::POSITION, EventLogTable::LOG));
            if (!is_numeric($highest)) {
                return;
            }
            $expired = \sprintf('%s < :highest AND %s < :before', EventLogTable::POSITION, EventLogTable::OCCURRED_AT);
            $parameters = ['highest' => (int) $highest, 'before' => $before];
            $types = ['before' => Types::DATETIME_IMMUTABLE];
            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s IN (SELECT %s FROM %s WHERE %s)', EventLogTable::CONSUMPTION, EventLogTable::EVENT_ID, EventLogTable::ID, EventLogTable::LOG, $expired),
                $parameters,
                $types,
            );
            $connection->executeStatement(\sprintf('DELETE FROM %s WHERE %s', EventLogTable::LOG, $expired), $parameters, $types);
        });
    }
}
