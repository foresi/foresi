<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Delivery;

use App\Audit\Application\AuditActor;
use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Api\JsonValues;
use App\Webhooks\Application\AttemptTrigger;
use App\Webhooks\Application\QueuedAttempt;
use App\Webhooks\Application\SendWebhook;
use App\Webhooks\Application\WebhookQueue;
use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Infrastructure\Api\WebhookEndpointApiController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Stores a ping as a delivery of its own and queues its one attempt, or queues
 * one more attempt of a stored delivery, on the webhooks transport. The ping
 * has the envelope of an event without a position, and is encoded once, so the
 * attempt signs the bytes it sends.
 */
final readonly class MessengerWebhookQueue implements WebhookQueue
{
    public const string PING_TYPE = 'webhooks.endpoint.ping';

    public function __construct(
        private WebhookDeliveries $deliveries,
        private MessageBusInterface $bus,
        private UrlGeneratorInterface $urls,
        private AuditActor $actor,
    ) {
    }

    public function ping(WebhookEndpoint $endpoint, \DateTimeImmutable $now): QueuedAttempt
    {
        $pingId = Id::generate();
        $body = json_encode([
            'id' => $pingId,
            'type' => self::PING_TYPE,
            'version' => 1,
            'occurred_at' => JsonValues::timestamp($now),
            'subject' => [
                'type' => 'webhook_endpoint',
                'id' => $endpoint->id(),
                'url' => $this->urls->generate(WebhookEndpointApiController::SHOW, ['id' => $endpoint->id()]),
            ],
            'data' => ['endpoint_id' => $endpoint->id()],
            'correlation_id' => null,
            'actor' => ['kind' => $this->actor->kind()->value],
        ], \JSON_THROW_ON_ERROR);
        $deliveryId = $this->deliveries->addPing($endpoint->id(), $pingId, $body, $now);

        return $this->queue($deliveryId, AttemptTrigger::Ping);
    }

    public function redeliver(string $deliveryId): QueuedAttempt
    {
        return $this->queue($deliveryId, AttemptTrigger::Manual);
    }

    private function queue(string $deliveryId, AttemptTrigger $trigger): QueuedAttempt
    {
        $attemptId = Id::generate();
        $this->bus->dispatch(new SendWebhook($deliveryId, $trigger, $attemptId));

        return new QueuedAttempt($deliveryId, $attemptId);
    }
}
