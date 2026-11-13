<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Api;

use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Webhooks\Application\AttemptTrigger;
use App\Webhooks\Application\SendWebhook;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;

/**
 * The deliveries of an endpoint over REST: listed newest first, each shown with
 * its attempts, and a finished one redelivered by hand once.
 */
final class WebhookDeliveryApiControllerTest extends WebhookApiTestCase
{
    public function testDeliveryLogListsAttempts(): void
    {
        $id = $this->stored($this->tenant);
        $tenant = $this->tenant->toString();
        $older = $this->rows->insert($tenant, $id, T::STATUS_FAILED, attempts: 2, nextAttemptAt: null, createdAt: '2026-11-13 08:00:00');
        $first = $this->rows->attempt($tenant, $older, '2026-11-13 08:00:00');
        $second = $this->rows->attempt($tenant, $older, '2026-11-13 08:00:05');
        $middle = $this->rows->insert($tenant, $id, T::STATUS_SUCCEEDED, attempts: 1, nextAttemptAt: null, createdAt: '2026-11-13 09:00:00');
        $newest = $this->rows->insert($tenant, $id, createdAt: '2026-11-13 10:00:00');
        $this->rows->insert($tenant, $this->stored($this->tenant), T::STATUS_FAILED);

        self::assertSame([[$newest, $middle], [$older]], $this->pages(self::ENDPOINTS.'/'.$id.'/deliveries', 2));

        $this->get(self::ENDPOINTS.'/'.$id.'/deliveries');
        self::assertSame('invoicing.invoice.issued', $this->at('data', 2, 'event_type'));
        self::assertSame('failed', $this->at('data', 2, 'status'));
        self::assertSame(2, $this->at('data', 2, 'attempt_count'));
        self::assertSame('event', $this->at('data', 2, 'kind'));
        $row = $this->at('data', 2);
        self::assertIsArray($row);
        self::assertArrayNotHasKey('body', $row);
        self::assertArrayNotHasKey('attempts', $row);

        $this->get(self::ENDPOINTS.'/'.$id.'/deliveries/'.$older);
        self::assertResponseIsSuccessful();
        self::assertSame($older, $this->at('data', 'id'));
        self::assertSame($id, $this->at('data', 'endpoint_id'));
        $attempts = $this->at('data', 'attempts');
        self::assertIsArray($attempts);
        self::assertSame([$first, $second], array_column($attempts, 'id'));
        self::assertSame(500, $this->at('data', 'attempts', 0, 'status_code'));
        self::assertSame(12, $this->at('data', 'attempts', 0, 'duration_ms'));
        self::assertNull($this->at('data', 'attempts', 0, 'error'));
        self::assertSame('schedule', $this->at('data', 'attempts', 0, 'trigger'));
        self::assertStringNotContainsString('body-marker', (string) $this->client->getResponse()->getContent());

        $this->get(self::ENDPOINTS.'/'.$this->stored($this->tenant).'/deliveries/'.$older);
        $this->assertProblem(404, 'not_found');
        $this->get(self::ENDPOINTS.'/'.$id.'/deliveries', ['cursor' => 'nonsense']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'cursor']);
    }

    public function testRedeliverQueuesOneManualAttempt(): void
    {
        $id = $this->stored($this->tenant);
        $delivery = $this->rows->insert($this->tenant->toString(), $id, T::STATUS_FAILED, attempts: 10, nextAttemptAt: null);

        $this->send('POST', $this->redeliver($id, $delivery), secret: $this->secret);
        $this->assertProblem(403, 'insufficient_scope');
        self::assertSame([], $this->queued());

        $this->send('POST', $this->redeliver($id, $delivery));
        self::assertResponseStatusCodeSame(202);
        $queued = $this->queued();
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(SendWebhook::class, $message);
        self::assertSame($delivery, $message->deliveryId);
        self::assertSame(AttemptTrigger::Manual, $message->trigger);
        self::assertNotNull($message->attemptId);
        self::assertSame($this->tenant->toString(), $queued[0]->last(TenantStamp::class)?->tenantId);
        self::assertSame($delivery, $this->at('data', 'delivery_id'));
        self::assertSame($message->attemptId, $this->at('data', 'attempt_id'));
        // The handler writes the attempt; the request only queues it.
        self::assertSame([], $this->rows->attempts($delivery));
        self::assertSame(T::STATUS_FAILED, $this->rows->delivery($delivery)[T::STATUS]);
    }

    public function testRedeliverOfAPendingDeliveryIs409(): void
    {
        $id = $this->stored($this->tenant);
        $pending = $this->rows->insert($this->tenant->toString(), $id);
        $ping = $this->rows->insert($this->tenant->toString(), $id, T::STATUS_FAILED, nextAttemptAt: null, kind: T::KIND_PING);

        $this->send('POST', $this->redeliver($id, $pending));
        $this->assertProblem(409, 'conflict');
        $this->send('POST', $this->redeliver($id, $ping));
        $this->assertProblem(409, 'conflict');
        self::assertSame([], $this->queued());
    }

    public function testRedeliverOnDisabledEndpointIs409(): void
    {
        $id = $this->stored($this->tenant, active: false);
        $delivery = $this->rows->insert($this->tenant->toString(), $id, T::STATUS_FAILED, nextAttemptAt: null);

        $this->send('POST', $this->redeliver($id, $delivery));

        $this->assertProblem(409, 'conflict');
        self::assertSame([], $this->queued());
    }

    public function testOtherTenantsDeliveryIs404(): void
    {
        $id = $this->stored($this->tenant);
        $foreign = $this->foreignTenant();
        $delivery = $this->rows->insert($foreign->toString(), $id, T::STATUS_FAILED, nextAttemptAt: null);

        $this->get(self::ENDPOINTS.'/'.$id.'/deliveries/'.$delivery);
        $this->assertProblem(404, 'not_found');
        $this->send('POST', $this->redeliver($id, $delivery));
        $this->assertProblem(404, 'not_found');
        $this->get(self::ENDPOINTS.'/'.$id.'/deliveries');
        self::assertSame([], $this->ids());
    }

    private function redeliver(string $endpointId, string $deliveryId): string
    {
        return self::ENDPOINTS.'/'.$endpointId.'/deliveries/'.$deliveryId.'/redeliver';
    }
}
