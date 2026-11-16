<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Mcp;

use App\Shared\Domain\Id;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;
use App\Webhooks\Infrastructure\Mcp\ListWebhookDeliveriesTool;

/**
 * foresi_list_webhook_deliveries answers what the deliveries route of the REST API answers.
 */
final class ListWebhookDeliveriesToolTest extends WebhookToolTestCase
{
    public function testItAnswersAsTheRestApiDoesAndPages(): void
    {
        $id = $this->stored($this->tenant);
        $tenant = $this->tenant->toString();
        $older = $this->rows->insert($tenant, $id, T::STATUS_FAILED, attempts: 2, nextAttemptAt: null, createdAt: '2026-11-13 08:00:00');
        $newer = $this->rows->insert($tenant, $id, T::STATUS_SUCCEEDED, attempts: 1, nextAttemptAt: null, createdAt: '2026-11-13 09:00:00');
        $this->rows->insert($tenant, $this->stored($this->tenant), T::STATUS_FAILED);

        $output = $this->call(ListWebhookDeliveriesTool::class, ['endpoint_id' => $id]);

        $this->get(self::ENDPOINTS.'/'.$id.'/deliveries', secret: $this->writer);
        self::assertSame($this->json()['data'], $output->data['items']);
        self::assertSame([$newer, $older], self::itemIds($output));
        self::assertSame('2 deliveries, newest first; no more match.', $output->summary);
        self::assertSame([[$newer], [$older]], $this->toolPages(ListWebhookDeliveriesTool::class, 1, ['endpoint_id' => $id]));
        self::assertStringNotContainsString('"body"', json_encode($output->data, \JSON_THROW_ON_ERROR));
    }

    public function testAnUnknownEndpointIsNotFound(): void
    {
        $refusal = $this->refusal(ListWebhookDeliveriesTool::class, ['endpoint_id' => Id::generate()]);

        self::assertSame('not_found', $refusal->errorCode);
    }

    public function testAnEndpointOfAnotherOrganisationIsNotFound(): void
    {
        $other = $this->fixtures->tenant('Other Organisation');
        $foreign = $this->stored($other);

        self::assertSame('not_found', $this->refusal(ListWebhookDeliveriesTool::class, ['endpoint_id' => $foreign])->errorCode);
    }

    public function testTheEndpointIsRequired(): void
    {
        self::assertSame('invalid_argument', $this->refusal(ListWebhookDeliveriesTool::class, [])->errorCode);
    }

    public function testAGuestMayNotReadTheDeliveriesOfAnInvoicingEndpoint(): void
    {
        $id = $this->stored($this->tenant);

        self::assertSame('forbidden', $this->refusal(ListWebhookDeliveriesTool::class, ['endpoint_id' => $id], $this->guest())->errorCode);
    }
}
