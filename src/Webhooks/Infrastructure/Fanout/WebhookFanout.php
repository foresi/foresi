<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Fanout;

use App\Events\Application\EventSink;
use App\Events\Application\Query\EventRow;
use App\Events\Infrastructure\Api\EventView;
use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Shared\Application\Event\EventReadAccess;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Webhooks\Application\AttemptTrigger;
use App\Webhooks\Application\SendWebhook;
use App\Webhooks\Domain\DisabledReason;
use App\Webhooks\Domain\EndpointUrl;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable;
use App\Webhooks\Infrastructure\Mail\EndpointDisabledMailer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Turns each numbered event into one pending delivery per active endpoint of
 * its organisation that subscribes to its name at its version, and queues the
 * first attempt. It runs inside the relay's transaction and makes no request:
 * the deliveries, their messages and the position are committed together. The
 * messages go through the Doctrine transport on the same connection, so they
 * are stored in that transaction too.
 *
 * The owner of an endpoint is asked again each time: one who may no longer read
 * the event's module gets nothing more, the endpoint is disabled and the owner
 * is told by a mail queued in the same transaction. The disabling is a plain
 * update in the relay's transaction, so it leaves no entry in the audit log.
 * The endpoints of an organisation are read once per batch; a row that cannot
 * be read is skipped with a log line, so it holds up no one else's events.
 */
final class WebhookFanout implements EventSink, ResetInterface
{
    /** @var array<string, array<string, array{owner: string, host: string, names: list<string>, version: int}>> by tenant, then by id */
    private array $endpoints = [];

    /** @var array<string, bool> by tenant, owner and module */
    private array $owners = [];

    /**
     * @param iterable<EventReadAccess> $access
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EventCatalogue $catalogue,
        private readonly EventView $view,
        private readonly TenantContext $context,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        #[AutowireIterator('app.event_read_access')]
        private readonly iterable $access,
        private readonly EndpointDisabledMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function accept(string $tenantId, EventRow $row): void
    {
        $body = null;
        $module = explode('.', $row->name, 2)[0];
        foreach ($this->endpointsOf($tenantId) as $id => $endpoint) {
            if ($endpoint['version'] !== $row->version || !\in_array($row->name, $endpoint['names'], true)) {
                continue;
            }
            if (!$this->ownerMayRead($tenantId, $endpoint['owner'], $module)) {
                $this->disable($id);
                $this->mailer->notify($tenantId, $endpoint['owner'], $id, $endpoint['host'], DisabledReason::OwnerLostAccess);
                unset($this->endpoints[$tenantId][$id]);

                continue;
            }
            $body ??= $this->body($row);
            $this->deliver($tenantId, $id, $row, $body);
        }
    }

    public function reset(): void
    {
        $this->endpoints = [];
        $this->owners = [];
    }

    /**
     * @return array<string, array{owner: string, host: string, names: list<string>, version: int}> by id
     */
    private function endpointsOf(string $tenantId): array
    {
        if (isset($this->endpoints[$tenantId])) {
            return $this->endpoints[$tenantId];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'owner_id', 'url', 'event_types', 'version')
            ->from('webhook_endpoint')
            ->where('tenant_id = :tenant')
            ->andWhere('active = :active')
            ->orderBy('id')
            ->setParameter('tenant', $tenantId)
            ->setParameter('active', true, Types::BOOLEAN)
            ->fetchAllAssociative();

        $platform = $this->connection->getDatabasePlatform();
        $endpoints = [];
        foreach ($rows as $row) {
            try {
                $patterns = Type::getType(Types::JSON)->convertToPHPValue($row['event_types'], $platform);
                if (!\is_array($patterns) || !is_numeric($row['version'])) {
                    throw new \UnexpectedValueException('A webhook endpoint holds a column of an unexpected type.');
                }
                $endpoints[$this->text($row['id'])] = [
                    'owner' => $this->text($row['owner_id']),
                    'host' => new EndpointUrl($this->text($row['url']))->host(),
                    'names' => $this->catalogue->matching(array_values(array_filter($patterns, \is_string(...)))),
                    'version' => (int) $row['version'],
                ];
            } catch (\UnexpectedValueException|\InvalidArgumentException|ConversionException $e) {
                $this->logger->error('The webhook endpoint {endpoint} was skipped: it cannot be read ({reason}).', ['endpoint' => \is_string($row['id']) ? $row['id'] : null, 'reason' => $e->getMessage()]);
            }
        }

        return $this->endpoints[$tenantId] = $endpoints;
    }

    /**
     * Asks the module's access service in the event's organisation. A module that
     * has none is not readable.
     */
    private function ownerMayRead(string $tenantId, string $owner, string $module): bool
    {
        $key = $tenantId.' '.$owner.' '.$module;
        if (isset($this->owners[$key])) {
            return $this->owners[$key];
        }

        $may = false;
        foreach ($this->access as $access) {
            if ($access->module() === $module) {
                $may = $this->inTenant($tenantId, static fn (): bool => $access->mayUserRead($owner));
                break;
            }
        }

        return $this->owners[$key] = $may;
    }

    /**
     * Runs a check with the event's organisation active, and puts back whatever
     * was active before: the relay runs with none, or inside a request of
     * another organisation when the queue is synchronous.
     *
     * @param \Closure(): bool $check
     */
    private function inTenant(string $tenantId, \Closure $check): bool
    {
        $tenant = TenantId::fromString($tenantId);
        if ($this->context->has() && $this->context->current()->equals($tenant)) {
            return $check();
        }

        $previous = $this->context->has() ? $this->context->current() : null;
        $this->context->switchTo($tenant);
        try {
            return $check();
        } finally {
            if ($previous instanceof TenantId) {
                $this->context->switchTo($previous);
            } else {
                $this->context->reset();
            }
        }
    }

    private function disable(string $endpointId): void
    {
        $this->connection->update('webhook_endpoint', [
            'active' => false,
            'disabled_reason' => DisabledReason::OwnerLostAccess->value,
            'updated_at' => $this->clock->now(),
        ], ['id' => $endpointId], [
            'active' => Types::BOOLEAN,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * The envelope of the feed without its position, encoded once, so every
     * attempt signs and sends the same bytes.
     */
    private function body(EventRow $row): string
    {
        $view = $this->view->of($row);
        unset($view['position']);

        return json_encode($view, \JSON_THROW_ON_ERROR);
    }

    private function deliver(string $tenantId, string $endpointId, EventRow $row, string $body): void
    {
        $id = Id::generate();
        $now = $this->clock->now();
        $this->connection->insert(WebhookDeliveryTable::DELIVERY, [
            WebhookDeliveryTable::ID => $id,
            WebhookDeliveryTable::TENANT_ID => $tenantId,
            WebhookDeliveryTable::ENDPOINT_ID => $endpointId,
            WebhookDeliveryTable::EVENT_ID => $row->id,
            WebhookDeliveryTable::EVENT_TYPE => $row->name,
            WebhookDeliveryTable::BODY => $body,
            WebhookDeliveryTable::STATUS => WebhookDeliveryTable::STATUS_PENDING,
            WebhookDeliveryTable::ATTEMPTS => 0,
            WebhookDeliveryTable::NEXT_ATTEMPT_AT => $now,
            WebhookDeliveryTable::KIND => WebhookDeliveryTable::KIND_EVENT,
            WebhookDeliveryTable::CREATED_AT => $now,
        ], [
            WebhookDeliveryTable::ATTEMPTS => Types::SMALLINT,
            WebhookDeliveryTable::NEXT_ATTEMPT_AT => Types::DATETIME_IMMUTABLE,
            WebhookDeliveryTable::CREATED_AT => Types::DATETIME_IMMUTABLE,
        ]);
        $this->bus->dispatch(new Envelope(new SendWebhook($id, AttemptTrigger::Schedule), [new TenantStamp($tenantId)]));
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('A webhook endpoint holds a column of an unexpected type.');
    }
}
