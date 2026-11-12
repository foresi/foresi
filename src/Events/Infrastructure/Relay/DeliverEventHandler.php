<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Relay;

use App\Events\Application\DeliverEvent;
use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Dbal\EventLogTable;
use App\Events\Infrastructure\Payload\EventPayloads;
use App\Shared\Application\Event\DomainEventListener;
use App\Shared\Application\Event\RecordedEvent;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Hands a stored event to one async listener, once. The consumption is recorded
 * in the listener's own transaction, so a listener that fails leaves no record
 * and runs again on the retry, while one that succeeded is skipped when the
 * message comes again. Two workers on the same message collide on the record:
 * the loser is retried and then skips.
 *
 * An event pruned from the log, or a listener removed since the relay, ends the
 * delivery with a log line: there is nothing left to hand over.
 */
#[AsMessageHandler]
final readonly class DeliverEventHandler
{
    public function __construct(
        private Connection $connection,
        private EventListeners $listeners,
        private EventCatalogue $catalogue,
        private EventPayloads $payloads,
        private DeliveryWork $work,
        private TenantContext $tenant,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeliverEvent $message): void
    {
        $listener = $this->listeners->get($message->listener);
        if (!$listener instanceof DomainEventListener) {
            $this->logger->warning('The event {event} was not delivered: no listener is named {listener} any more.', ['event' => $message->eventId, 'listener' => $message->listener]);

            return;
        }

        try {
            $this->work->run(fn () => $this->deliver($message, $listener));
        } catch (UniqueConstraintViolationException $duplicate) {
            throw new RecoverableMessageHandlingException(\sprintf('The event %s is being delivered to %s by another worker.', $message->eventId, $message->listener), $duplicate->getCode(), previous: $duplicate);
        }
    }

    private function deliver(DeliverEvent $message, DomainEventListener $listener): void
    {
        if (false !== $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE %s = ? AND %s = ?', EventLogTable::CONSUMPTION, EventLogTable::LISTENER, EventLogTable::EVENT_ID),
            [$message->listener, $message->eventId],
        )) {
            return;
        }

        $row = $this->connection->fetchAssociative(\sprintf('SELECT * FROM %s WHERE %s = ?', EventLogTable::LOG, EventLogTable::ID), [$message->eventId]);
        if (false === $row) {
            $this->logger->warning('The event {event} was not delivered to {listener}: it was pruned from the log.', ['event' => $message->eventId, 'listener' => $message->listener]);

            return;
        }
        if ($this->tenant->current()->toString() !== $row[EventLogTable::TENANT_ID]) {
            throw new UnrecoverableMessageHandlingException(\sprintf('The event %s belongs to another tenant than its delivery.', $message->eventId));
        }

        $this->connection->insert(EventLogTable::CONSUMPTION, [
            EventLogTable::LISTENER => $message->listener,
            EventLogTable::EVENT_ID => $message->eventId,
            EventLogTable::CONSUMED_AT => $this->clock->now(),
        ], [EventLogTable::CONSUMED_AT => Types::DATETIME_IMMUTABLE]);

        $listener($this->recorded($row));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function recorded(array $row): RecordedEvent
    {
        $name = $this->text($row[EventLogTable::NAME]);
        $version = $row[EventLogTable::VERSION];
        $type = $this->catalogue->byName($name, is_numeric($version) ? (int) $version : 0);
        $data = $this->connection->convertToPHPValue($row[EventLogTable::DATA], Types::JSON);
        $occurredAt = $this->connection->convertToPHPValue($row[EventLogTable::OCCURRED_AT], Types::DATETIME_IMMUTABLE);
        $correlation = $row[EventLogTable::CORRELATION_ID];

        return new RecordedEvent(
            $this->text($row[EventLogTable::ID]),
            $name,
            $type->version,
            $occurredAt instanceof \DateTimeImmutable ? $occurredAt : throw new \UnexpectedValueException('The event log holds a column of an unexpected type.'),
            \is_string($correlation) ? $correlation : null,
            $this->payloads->denormalise($type, $this->facts($data)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(mixed $data): array
    {
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('The facts of a stored event are not an object.');
        }
        $facts = [];
        foreach ($data as $key => $value) {
            $facts[(string) $key] = $value;
        }

        return $facts;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('The event log holds a column of an unexpected type.');
    }
}
