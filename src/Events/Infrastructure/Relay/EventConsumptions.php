<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Relay;

use App\Events\Infrastructure\Dbal\EventLogTable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * The record of which listener was handed which event, written in the
 * listener's transaction. Two workers on the same message collide on it: the
 * loser's delivery is retried, and the retry finds the record and skips.
 */
final readonly class EventConsumptions
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function has(string $listener, string $eventId): bool
    {
        return false !== $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE %s = ? AND %s = ?', EventLogTable::CONSUMPTION, EventLogTable::LISTENER, EventLogTable::EVENT_ID),
            [$listener, $eventId],
        );
    }

    /**
     * @throws RecoverableMessageHandlingException when another worker recorded it meanwhile
     */
    public function record(string $listener, string $eventId): void
    {
        try {
            $this->connection->insert(EventLogTable::CONSUMPTION, [
                EventLogTable::LISTENER => $listener,
                EventLogTable::EVENT_ID => $eventId,
                EventLogTable::CONSUMED_AT => $this->clock->now(),
            ], [EventLogTable::CONSUMED_AT => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException $duplicate) {
            throw new RecoverableMessageHandlingException(\sprintf('The event %s is being delivered to %s by another worker.', $eventId, $listener), $duplicate->getCode(), previous: $duplicate);
        }
    }
}
