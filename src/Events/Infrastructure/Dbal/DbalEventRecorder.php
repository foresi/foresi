<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Dbal;

use App\Audit\Application\AuditActor;
use App\Audit\Application\AuditCorrelation;
use App\Audit\Application\TokenAuditActor;
use App\Events\Application\RelayEvents;
use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Payload\EventPayloads;
use App\Shared\Application\Event\EventOutbox;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Writes an event as one row of the log on the connection of the use case, so
 * the row is committed or rolled back with the change it describes. The row has
 * no position yet: the relay gives it one after the commit. Listeners that run
 * synchronously are called right after the insert, in the same transaction.
 *
 * Holds the count of events recorded in the running outermost transaction, so
 * state is reset between messages of a long-running worker by the transaction
 * hooks, which always end the transaction one way or the other.
 */
final class DbalEventRecorder implements EventRecorder, EventOutbox
{
    private int $pending = 0;

    public function __construct(
        private readonly Connection $connection,
        private readonly EventCatalogue $catalogue,
        private readonly EventPayloads $payloads,
        private readonly TenantContext $tenant,
        private readonly AuditCorrelation $correlation,
        private readonly AuditActor $actor,
        private readonly ClockInterface $clock,
        private readonly EventDispatcherInterface $listeners,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(DomainEventPayload $event): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('An event is recorded inside a transaction, in the unit of work that makes it true.');
        }

        $type = $this->catalogue->byClass($event::class);
        $subject = $event->subject();
        $this->connection->insert(EventLogTable::LOG, [
            EventLogTable::ID => Id::generate(),
            EventLogTable::TENANT_ID => $this->tenant->current()->toString(),
            EventLogTable::NAME => $type->name,
            EventLogTable::VERSION => $type->version,
            EventLogTable::OCCURRED_AT => $this->clock->now(),
            EventLogTable::SUBJECT_TYPE => $subject->type,
            EventLogTable::SUBJECT_ID => $subject->id,
            EventLogTable::DATA => $this->payloads->normalise($event),
            EventLogTable::CORRELATION_ID => $this->correlation->current(),
            EventLogTable::ACTOR_KIND => $this->actor->kind()->value,
            EventLogTable::USER_ID => $this->actor->userId(),
            EventLogTable::API_TOKEN_ID => $this->actor instanceof TokenAuditActor ? $this->actor->apiTokenId() : null,
        ], [
            EventLogTable::OCCURRED_AT => Types::DATETIME_IMMUTABLE,
            EventLogTable::DATA => Types::JSON,
        ]);
        ++$this->pending;

        $this->listeners->dispatch($event);
    }

    public function committed(): void
    {
        $recorded = $this->pending > 0;
        $this->pending = 0;
        if (!$recorded) {
            return;
        }
        // The change is stored: a failed nudge must not fail the caller, who would retry and duplicate it.
        // The rows keep a null position and the periodic sweep of the relay picks them up.
        try {
            $this->bus->dispatch(new RelayEvents());
        } catch (\Throwable $failure) {
            $this->logger->error('The relay of domain events was not nudged after a commit: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }

    public function rolledBack(): void
    {
        $this->pending = 0;
    }
}
