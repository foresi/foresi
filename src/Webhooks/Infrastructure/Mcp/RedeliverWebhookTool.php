<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Mcp;

use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\WriteTool;
use App\Webhooks\Application\ManageWebhookEndpoints;

/**
 * Sends a finished delivery again, as POST
 * /api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}/redeliver does: one
 * more attempt with the same webhook-id and a new signature.
 */
final readonly class RedeliverWebhookTool implements WriteTool
{
    public function __construct(private ManageWebhookEndpoints $manage)
    {
    }

    public function name(): string
    {
        return 'foresi_redeliver_webhook';
    }

    public function title(): string
    {
        return 'Redeliver a webhook';
    }

    public function description(): string
    {
        return 'Sends a finished (succeeded or failed) delivery to its endpoint once more: the same event and webhook-id, a new timestamp and signature. '
            .'The endpoint must be active; a delivery still on its schedule and a ping cannot be redelivered. The attempt is queued, not made: its outcome shows in foresi_list_webhook_deliveries. '
            .'Returns {delivery_id, attempt_id}. A receiver that does not deduplicate on webhook-id may process the event twice.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'endpoint_id' => ToolArguments::idSchema('The id of the endpoint, as foresi_list_webhook_endpoints returns it.'),
                'delivery_id' => ToolArguments::idSchema('The id of the delivery, as foresi_list_webhook_deliveries returns it.'),
            ],
            'required' => ['endpoint_id', 'delivery_id'],
            'additionalProperties' => false,
        ];
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function call(array $arguments): ToolOutput
    {
        $endpointId = WebhookToolArguments::required($arguments, 'endpoint_id');
        $deliveryId = WebhookToolArguments::required($arguments, 'delivery_id');

        $queued = WebhookToolErrors::refuse(fn (): \App\Webhooks\Application\QueuedAttempt => $this->manage->redeliver($endpointId, $deliveryId));

        return new ToolOutput(['delivery_id' => $queued->deliveryId, 'attempt_id' => $queued->attemptId], 'One more attempt is queued. Read the deliveries of the endpoint to see how it went.');
    }
}
