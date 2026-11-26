<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Infrastructure\Fanout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Foresi\Events\Application\EventSink;
use Foresi\Events\Application\Query\EventRow;
use Foresi\Events\Infrastructure\Api\EventView;
use Foresi\Events\Infrastructure\Catalogue\EventCatalogue;
use Foresi\Shared\Application\Event\EventReadAccess;
use Foresi\Shared\Application\Event\EventSubjectAccess;
use Foresi\Shared\Contract\Event\EventSubject;
use Foresi\Shared\Domain\Id;
use Foresi\Webhooks\Application\AttemptTrigger;
use Foresi\Webhooks\Application\SendWebhook;
use Foresi\Webhooks\Domain\DisabledReason;
use Foresi\Webhooks\Domain\EndpointUrl;
use Foresi\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable;
use Foresi\Webhooks\Infrastructure\Mail\EndpointDisabledMailer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Turns each numbered event into one pending delivery per active endpoint
 * that subscribes to its name at its version, and queues the
 * first attempt. It runs inside the relay's transaction and makes no request:
 * the deliveries, their messages and the position are committed together. The
 * messages go through the Doctrine transport on the same connection, so they
 * are stored in that transaction too.
 *
 * The owner of an endpoint is asked again each time: one who may no longer read
 * the event's module gets nothing more, the endpoint is disabled and the owner
 * is told by a mail queued in the same transaction. The disabling is a plain
 * update in the relay's transaction, so it leaves no entry in the audit log.
 * The endpoints are read once per batch; a row that cannot
 * be read is skipped with a log line, so it holds up no one else's events.
 */
final class WebhookFanout implements EventSink, ResetInterface
{
    /** @var array<string, array{owner: string, host: string, names: list<string>, version: int}>|null by id */
    private ?array $endpoints = null;

    /** @var array<string, bool> by owner and module */
    private array $owners = [];

    /**
     * @param iterable<EventReadAccess>    $access
     * @param iterable<EventSubjectAccess> $subjects
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EventCatalogue $catalogue,
        private readonly EventView $view,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        #[AutowireIterator(EventReadAccess::READ_ACCESS_TAG)]
        private readonly iterable $access,
        private readonly EndpointDisabledMailer $mailer,
        private readonly LoggerInterface $logger,
        #[AutowireIterator(EventSubjectAccess::SUBJECT_ACCESS_TAG)]
        private readonly iterable $subjects = [],
    ) {
    }

    public function accept(EventRow $row): void
    {
        $body = null;
        $module = explode('.', $row->name, 2)[0];
        foreach ($this->endpoints() as $id => $endpoint) {
            if ($endpoint['version'] !== $row->version || !\in_array($row->name, $endpoint['names'], true)) {
                continue;
            }
            if (!$this->ownerMayRead($endpoint['owner'], $module)) {
                $this->disable($id);
                $this->mailer->notify($endpoint['owner'], $id, $endpoint['host'], DisabledReason::OwnerLostAccess);
                unset($this->endpoints[$id]);

                continue;
            }
            // An event about what the owner does not see, such as a private task, is not theirs to receive.
            if (!$this->ownerSees($endpoint['owner'], $module, $row)) {
                continue;
            }
            $body ??= $this->body($row);
            $this->deliver($id, $row, $body);
        }
    }

    public function reset(): void
    {
        $this->endpoints = null;
        $this->owners = [];
    }

    /**
     * @return array<string, array{owner: string, host: string, names: list<string>, version: int}> by id
     */
    private function endpoints(): array
    {
        if (null !== $this->endpoints) {
            return $this->endpoints;
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'owner_id', 'url', 'event_types', 'version')
            ->from('webhook_endpoint')
            ->where('active = :active')
            ->orderBy('id')
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

        return $this->endpoints = $endpoints;
    }

    /**
     * Asks the module's access service. A module that has none is not readable.
     */
    private function ownerMayRead(string $owner, string $module): bool
    {
        $key = $owner.' '.$module;
        if (isset($this->owners[$key])) {
            return $this->owners[$key];
        }

        $may = false;
        foreach ($this->access as $access) {
            if ($access->module() === $module) {
                $may = $access->mayUserRead($owner);
                break;
            }
        }

        return $this->owners[$key] = $may;
    }

    private function ownerSees(string $owner, string $module, EventRow $row): bool
    {
        foreach ($this->subjects as $subjects) {
            if ($subjects->module() === $module && !$subjects->userSees($owner, new EventSubject($row->subjectType, $row->subjectId))) {
                return false;
            }
        }

        return true;
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

    private function deliver(string $endpointId, EventRow $row, string $body): void
    {
        $id = Id::generate();
        $now = $this->clock->now();
        $this->connection->insert(WebhookDeliveryTable::DELIVERY, [
            WebhookDeliveryTable::ID => $id,
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
        $this->bus->dispatch(new SendWebhook($id, AttemptTrigger::Schedule));
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('A webhook endpoint holds a column of an unexpected type.');
    }
}
