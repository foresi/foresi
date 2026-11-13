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
use App\Webhooks\Infrastructure\Api\EndpointView;

/**
 * Changes a webhook endpoint, as PATCH /api/v1/webhook-endpoints/{id} does:
 * only what is given changes, and active switches it off (reason manual) or on.
 */
final readonly class UpdateWebhookEndpointTool implements WriteTool
{
    public function __construct(private ManageWebhookEndpoints $manage)
    {
    }

    public function name(): string
    {
        return 'foresi_update_webhook_endpoint';
    }

    public function title(): string
    {
        return 'Update a webhook endpoint';
    }

    public function description(): string
    {
        return 'Changes a webhook endpoint: only the arguments you give change, the rest stays. '
            .'Set active to false to disable it (it stops receiving events; the reason is "manual") and to true to enable it again, which also clears a disabled reason. '
            .'A new url must be https and reach a public host; event_types replace the old list and you must be able to read each module. A description of null or "" clears it. '
            .'Returns the endpoint as it is now, without its secret.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'endpoint_id' => ToolArguments::idSchema('The id of the endpoint, as foresi_list_webhook_endpoints returns it.'),
                'url' => ['type' => 'string', 'description' => 'The new https URL.'],
                'description' => ['type' => ['string', 'null'], 'description' => 'The new description; null or "" clears it.'],
                'event_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 100, 'description' => 'The new list of event names or "module.*"; it replaces the old one.'],
                'version' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The new version of the events to send.'],
                'active' => ['type' => 'boolean', 'description' => 'false disables the endpoint, true enables it.'],
            ],
            'required' => ['endpoint_id'],
            'additionalProperties' => false,
        ];
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function call(array $arguments): ToolOutput
    {
        $id = WebhookToolArguments::required($arguments, 'endpoint_id');
        // Left out keeps the description, null or blank clears it.
        $description = ToolArguments::text($arguments, 'description') ?? (\array_key_exists('description', $arguments) ? '' : null);

        $endpoint = WebhookToolErrors::refuse(fn (): \App\Webhooks\Domain\WebhookEndpoint => $this->manage->update(
            $id,
            ToolArguments::text($arguments, 'url'),
            $description,
            WebhookToolArguments::eventTypes($arguments),
            WebhookToolArguments::integer($arguments, 'version'),
            WebhookToolArguments::boolean($arguments, 'active'),
        ));

        return new ToolOutput(EndpointView::of($endpoint), 'The endpoint as it is now.');
    }
}
