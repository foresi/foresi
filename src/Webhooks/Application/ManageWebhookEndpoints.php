<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Shared\Application\SealerUnavailable;
use App\Shared\Domain\Id;
use App\Webhooks\Application\Query\DeliveryRecords;
use App\Webhooks\Application\Query\DeliveryRow;
use App\Webhooks\Application\Query\WebhookEndpointList;
use App\Webhooks\Domain\DisabledReason;
use App\Webhooks\Domain\EventTypes;
use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Domain\WebhookSecret;
use Psr\Clock\ClockInterface;

/**
 * Registers, changes and removes the endpoints of the organisation, and the
 * attempts a person asks for by hand: for REST, MCP and the screen alike. Each
 * call is refused unless the signed-in person may read every module the
 * endpoint subscribes to (WebhookAccess); the token's scope is the caller's to
 * check. The person who registers an endpoint owns it.
 *
 * Secrets leave sealed only through create(), rotate() and reveal(), and never
 * through an exception or the audit log.
 */
final readonly class ManageWebhookEndpoints
{
    public const int DEFAULT_KEEP_PREVIOUS_HOURS = 24;
    /** Seven days, as long as Stripe keeps a rolled secret. */
    public const int MAX_KEEP_PREVIOUS_HOURS = 168;

    public function __construct(
        private WebhookEndpoints $endpoints,
        private WebhookEndpointList $list,
        private WebhookAccess $access,
        private WebhookUrlPolicy $urls,
        private WebhookSecrets $secrets,
        private WebhookWork $work,
        private WebhookQueue $queue,
        private DeliveryRecords $deliveries,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Registers an endpoint owned by the signed-in person and queues a ping of
     * it once it is stored.
     *
     * @param list<string> $eventTypes event names and "module.*"
     *
     * @throws InvalidWebhookEndpoint
     * @throws InvalidWebhookUrl
     * @throws WebhookNotAllowed
     * @throws WebhookSecretsUnavailable
     */
    public function create(string $url, ?string $description, array $eventTypes, int $version = 1): CreatedEndpoint
    {
        $owner = $this->access->person();
        $types = $this->eventTypes($eventTypes);
        $this->access->denyUnlessMaySubscribe($types);
        $vetted = $this->urls->vet($url);
        $this->validVersion($version);
        $this->validDescription($description);
        $secret = WebhookSecret::generate();
        $sealed = $this->seal($secret);
        $now = $this->now();

        $endpoint = WebhookEndpoint::create(Id::generate(), $vetted, $description, $types, $version, $sealed, $owner, $now);
        $this->work->run(fn () => $this->endpoints->add($endpoint));
        $this->work->run(fn (): QueuedAttempt => $this->queue->ping($endpoint, $now));

        return new CreatedEndpoint($endpoint, $secret);
    }

    /**
     * Changes what is given and keeps the rest. A blank description clears it;
     * null keeps it. Active false disables the endpoint by hand, true enables it.
     *
     * @param list<string>|null $eventTypes
     *
     * @throws UnknownWebhookEndpoint
     * @throws InvalidWebhookEndpoint
     * @throws InvalidWebhookUrl
     * @throws WebhookNotAllowed
     */
    public function update(string $id, ?string $url = null, ?string $description = null, ?array $eventTypes = null, ?int $version = null, ?bool $active = null): WebhookEndpoint
    {
        $endpoint = $this->list->show($id);
        $types = null;
        if (null !== $eventTypes) {
            $types = $this->eventTypes($eventTypes);
            $this->access->denyUnlessMaySubscribe($types);
        }
        $vetted = null === $url ? null : $this->urls->vet($url);
        if (null !== $version) {
            $this->validVersion($version);
        }
        $this->validDescription($description);
        $now = $this->now();

        $this->work->run(static function () use ($endpoint, $now, $vetted, $description, $types, $version, $active): void {
            $endpoint->change($now, $vetted, $description, $types, $version);
            if (false === $active && $endpoint->isActive()) {
                $endpoint->disable(DisabledReason::Manual, $now);
            } elseif (true === $active && !$endpoint->isActive()) {
                $endpoint->enable($now);
            }
        });

        return $endpoint;
    }

    /**
     * Removes the endpoint with its deliveries and their attempts.
     *
     * @throws UnknownWebhookEndpoint
     * @throws WebhookNotAllowed
     */
    public function delete(string $id): void
    {
        $endpoint = $this->list->show($id);
        $this->work->run(fn () => $this->endpoints->remove($endpoint));
    }

    /**
     * Signs with a new secret from now on, and with the current one too for the
     * hours given; zero drops it at once.
     *
     * @throws UnknownWebhookEndpoint
     * @throws InvalidWebhookEndpoint
     * @throws WebhookNotAllowed
     * @throws WebhookSecretsUnavailable
     */
    public function rotate(string $id, int $keepPreviousHours = self::DEFAULT_KEEP_PREVIOUS_HOURS): WebhookSecret
    {
        if ($keepPreviousHours < 0 || $keepPreviousHours > self::MAX_KEEP_PREVIOUS_HOURS) {
            throw new InvalidWebhookEndpoint('keep_previous_hours', \sprintf('The previous secret is kept for 0 to %d hours.', self::MAX_KEEP_PREVIOUS_HOURS));
        }
        $endpoint = $this->list->show($id);
        $secret = WebhookSecret::generate();
        $sealed = $this->seal($secret);
        $now = $this->now();

        $this->work->run(static fn () => $endpoint->rotate($sealed, $now->modify(\sprintf('+%d hours', $keepPreviousHours)), $now));

        return $secret;
    }

    /**
     * The secret the endpoint is signed with now.
     *
     * @throws UnknownWebhookEndpoint
     * @throws WebhookNotAllowed
     * @throws WebhookSecretsUnavailable
     */
    public function reveal(string $id): WebhookSecret
    {
        $endpoint = $this->list->show($id);
        $this->requireSecrets();
        try {
            return $this->secrets->open($endpoint->signingSecrets($this->now())[0]);
        } catch (SealerUnavailable $e) {
            throw new WebhookSecretsUnavailable($e);
        }
    }

    /**
     * @throws UnknownWebhookEndpoint
     * @throws WebhookNotAllowed
     */
    public function enable(string $id): WebhookEndpoint
    {
        return $this->update($id, active: true);
    }

    /**
     * Stops sending to the endpoint, as a person decided (reason manual).
     *
     * @throws UnknownWebhookEndpoint
     * @throws WebhookNotAllowed
     */
    public function disable(string $id): WebhookEndpoint
    {
        return $this->update($id, active: false);
    }

    /**
     * Queues a ping of an active endpoint: one attempt, no retries.
     *
     * @throws UnknownWebhookEndpoint
     * @throws WebhookEndpointDisabled
     * @throws WebhookNotAllowed
     * @throws WebhookSecretsUnavailable
     */
    public function ping(string $id): QueuedAttempt
    {
        $endpoint = $this->activeEndpoint($id);
        $now = $this->now();

        return $this->work->run(fn (): QueuedAttempt => $this->queue->ping($endpoint, $now));
    }

    /**
     * Queues one more attempt of a finished delivery of an event, with the same
     * webhook-id and a new signature.
     *
     * @throws UnknownWebhookEndpoint
     * @throws UnknownWebhookDelivery
     * @throws DeliveryNotRedeliverable
     * @throws WebhookEndpointDisabled
     * @throws WebhookNotAllowed
     * @throws WebhookSecretsUnavailable
     */
    public function redeliver(string $endpointId, string $deliveryId): QueuedAttempt
    {
        $endpoint = $this->activeEndpoint($endpointId);
        $delivery = Id::isValid($deliveryId) ? $this->deliveries->find($endpoint->id(), $deliveryId) : null;
        if (!$delivery instanceof DeliveryRow) {
            throw new UnknownWebhookDelivery();
        }
        if ($delivery->isPing()) {
            throw DeliveryNotRedeliverable::ping();
        }
        if ($delivery->isPending()) {
            throw DeliveryNotRedeliverable::pending();
        }

        return $this->work->run(fn (): QueuedAttempt => $this->queue->redeliver($endpoint->tenantId()->toString(), $delivery->id));
    }

    private function activeEndpoint(string $id): WebhookEndpoint
    {
        $endpoint = $this->list->show($id);
        if (!$endpoint->isActive()) {
            throw new WebhookEndpointDisabled();
        }
        $this->requireSecrets();

        return $endpoint;
    }

    /**
     * @param list<string> $patterns
     */
    private function eventTypes(array $patterns): EventTypes
    {
        try {
            return EventTypes::of($patterns);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidWebhookEndpoint('event_types', $e->getMessage());
        }
    }

    private function validVersion(int $version): void
    {
        if ($version < 1 || $version > WebhookEndpoint::MAX_VERSION) {
            throw new InvalidWebhookEndpoint('version', \sprintf('The version is a whole number from 1 to %d.', WebhookEndpoint::MAX_VERSION));
        }
    }

    private function validDescription(?string $description): void
    {
        if (null !== $description && mb_strlen(trim($description)) > WebhookEndpoint::DESCRIPTION_MAX_LENGTH) {
            throw new InvalidWebhookEndpoint('description', \sprintf('The description has at most %d characters.', WebhookEndpoint::DESCRIPTION_MAX_LENGTH));
        }
    }

    private function seal(WebhookSecret $secret): string
    {
        $this->requireSecrets();
        try {
            return $this->secrets->seal($secret);
        } catch (SealerUnavailable $e) {
            throw new WebhookSecretsUnavailable($e);
        }
    }

    private function requireSecrets(): void
    {
        if (!$this->secrets->isConfigured()) {
            throw new WebhookSecretsUnavailable();
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
