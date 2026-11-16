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
 * Deletes a webhook endpoint with its deliveries and attempts, as
 * DELETE /api/v1/webhook-endpoints/{id} does.
 */
final readonly class DeleteWebhookEndpointTool implements WriteTool
{
    public function __construct(private ManageWebhookEndpoints $manage)
    {
    }

    public function name(): string
    {
        return 'foresi_delete_webhook_endpoint';
    }

    public function title(): string
    {
        return 'Delete a webhook endpoint';
    }

    public function description(): string
    {
        return 'Deletes a webhook endpoint for good, with its deliveries and their attempts. It stops receiving events at once and this cannot be undone; to stop it only for a while, disable it with foresi_update_webhook_endpoint instead.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['endpoint_id' => ToolArguments::idSchema('The id of the endpoint, as foresi_list_webhook_endpoints returns it.')],
            'required' => ['endpoint_id'],
            'additionalProperties' => false,
        ];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function call(array $arguments): ToolOutput
    {
        $id = WebhookToolArguments::required($arguments, 'endpoint_id');
        WebhookToolErrors::refuse(fn () => $this->manage->delete($id));

        return new ToolOutput(['id' => $id, 'deleted' => true], 'The endpoint and its deliveries are deleted.');
    }
}
