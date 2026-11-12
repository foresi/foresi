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
            'id' => Id::generate(),
            'tenant_id' => $this->tenant->current()->toString(),
            'name' => $type->name,
            'version' => $type->version,
            'occurred_at' => $this->clock->now(),
            'subject_type' => $subject->type,
            'subject_id' => $subject->id,
            'data' => $this->payloads->normalise($event),
            'correlation_id' => $this->correlation->current(),
            'actor_kind' => $this->actor->kind()->value,
            'user_id' => $this->actor->userId(),
            'api_token_id' => $this->actor instanceof TokenAuditActor ? $this->actor->apiTokenId() : null,
        ], [
            'occurred_at' => Types::DATETIME_IMMUTABLE,
            'data' => Types::JSON,
        ]);
        ++$this->pending;

        $this->listeners->dispatch($event);
    }

    public function committed(): void
    {
        $recorded = $this->pending > 0;
        $this->pending = 0;
        if ($recorded) {
            $this->bus->dispatch(new RelayEvents());
        }
    }

    public function rolledBack(): void
    {
        $this->pending = 0;
    }
}
