<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Mcp;

use App\Webhooks\Infrastructure\Mcp\ListWebhookEndpointsTool;

/**
 * foresi_list_webhook_endpoints answers what GET /api/v1/webhook-endpoints answers.
 */
final class ListWebhookEndpointsToolTest extends WebhookToolTestCase
{
    public function testTheToolDescribesItsArguments(): void
    {
        $tool = $this->tool(ListWebhookEndpointsTool::class);

        self::assertSame('foresi_list_webhook_endpoints', $tool->name());
        self::assertSame(['limit', 'cursor'], self::argumentNames($tool));
        self::assertFalse($tool->inputSchema()['additionalProperties']);
    }

    public function testItAnswersAsTheRestApiDoesWithoutSecrets(): void
    {
        $first = $this->stored($this->tenant);
        $second = $this->stored($this->tenant, ['invoicing.invoice.paid'], false);

        $output = $this->call(ListWebhookEndpointsTool::class);

        $this->get(self::ENDPOINTS, secret: $this->writer);
        self::assertSame($this->json()['data'], $output->data['items']);
        self::assertNull($output->data['next_cursor']);
        self::assertSame([$second, $first], self::itemIds($output));
        self::assertSame('2 webhook endpoints, newest first; no more match.', $output->summary);
        self::assertStringNotContainsString('whsec_', json_encode($output->data, \JSON_THROW_ON_ERROR));
        $items = $output->data['items'];
        self::assertIsArray($items);
        self::assertIsArray($items[0]);
        self::assertNotContains('secret', array_keys($items[0]));
    }

    public function testItPagesByCursor(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->stored($this->tenant);
        }

        $pages = $this->toolPages(ListWebhookEndpointsTool::class, 2);

        self::assertSame([2, 1], array_map(count(...), $pages));
    }

    public function testAGuestSeesNoEndpoints(): void
    {
        $this->stored($this->tenant);

        $output = $this->call(ListWebhookEndpointsTool::class, [], $this->guest());

        self::assertSame([], $output->data['items']);
        self::assertSame('No webhook endpoints match.', $output->summary);
    }

    public function testABadCursorAndALimitOutOfRangeAreInvalidArguments(): void
    {
        self::assertSame('invalid_argument', $this->refusal(ListWebhookEndpointsTool::class, ['cursor' => 'nonsense'])->errorCode);
        self::assertSame('invalid_argument', $this->refusal(ListWebhookEndpointsTool::class, ['limit' => 0])->errorCode);
    }
}
