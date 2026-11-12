<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Relay;

use App\Events\Application\DeliverEvent;
use App\Events\Infrastructure\Dbal\EventLogTable;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Gives committed events their positions and enqueues their deliveries. Only one
 * relay runs at a time, under a lock, and it numbers on from the highest
 * position in its own transaction, so the positions have no gap and follow the
 * order in which the rows became visible: a row that commits late gets a
 * higher position, never a hole below the others.
 *
 * The deliveries go through the Doctrine transport, on the same connection, so
 * they are stored in the relay's transaction: a row is numbered together with
 * the messages to its listeners or, when enqueueing fails, stays unnumbered and
 * is picked up by the next run. Works with no tenant: each message carries the
 * tenant of its event.
 */
final readonly class EventRelay
{
    public const string LOCK = 'event-relay';
    /** Seconds the relay may hold its lock; a crashed worker frees it after that. */
    public const float LOCK_TTL = 300.0;
    public const int BATCH = 500;

    public function __construct(
        private Connection $connection,
        private LockFactory $locks,
        private EventListeners $listeners,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Numbers one batch of the oldest unnumbered rows.
     *
     * @return int the number of rows numbered
     */
    public function relay(int $batch = self::BATCH): int
    {
        $lock = $this->locks->createLock(self::LOCK, self::LOCK_TTL);
        $lock->acquire(true);
        try {
            return $this->connection->transactional(fn (): int => $this->number($batch));
        } finally {
            $lock->release();
        }
    }

    /**
     * Numbers batches until one comes back short, so nothing waits for the next run.
     *
     * @return int the number of rows numbered
     */
    public function relayAll(int $batch = self::BATCH): int
    {
        $total = 0;
        do {
            $numbered = $this->relay($batch);
            $total += $numbered;
        } while ($numbered === $batch);

        return $total;
    }

    private function number(int $batch): int
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(EventLogTable::ID, EventLogTable::TENANT_ID, EventLogTable::NAME)
            ->from(EventLogTable::LOG)
            ->where(EventLogTable::POSITION.' IS NULL')
            ->orderBy(EventLogTable::ID)
            ->setMaxResults($batch)
            ->fetchAllAssociative();
        if ([] === $rows) {
            return 0;
        }

        $highest = $this->connection->fetchOne(\sprintf('SELECT MAX(%s) FROM %s', EventLogTable::POSITION, EventLogTable::LOG));
        $position = is_numeric($highest) ? (int) $highest : 0;
        foreach ($rows as $row) {
            $id = $this->text($row[EventLogTable::ID]);
            $this->connection->update(EventLogTable::LOG, [EventLogTable::POSITION => ++$position], [EventLogTable::ID => $id]);
            foreach ($this->listeners->idsFor($this->text($row[EventLogTable::NAME])) as $listener) {
                $this->bus->dispatch(new Envelope(new DeliverEvent($id, $listener), [new TenantStamp($this->text($row[EventLogTable::TENANT_ID]))]));
            }
        }

        return \count($rows);
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('The event log holds a column of an unexpected type.');
    }
}
