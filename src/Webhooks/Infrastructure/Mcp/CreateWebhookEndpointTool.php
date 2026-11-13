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
 * Registers a webhook endpoint, as POST /api/v1/webhook-endpoints does: the
 * answer is the endpoint with its signing secret, which is shown here and by
 * rotating, and a ping is queued.
 */
final readonly class CreateWebhookEndpointTool implements WriteTool
{
    public function __construct(private ManageWebhookEndpoints $manage)
    {
    }

    public function name(): string
    {
        return 'foresi_create_webhook_endpoint';
    }

    public function title(): string
    {
        return 'Create a webhook endpoint';
    }

    public function description(): string
    {
        return 'Registers a URL that receives the domain events you choose as signed Standard Webhooks requests, and sends it a ping. '
            .'The url must be https and reach a public host. event_types are event names such as invoicing.invoice.paid or "module.*" for every event of a module (see foresi_list_event_types); you must be able to read each module. A bare "*" is refused. '
            .'Returns the endpoint with its secret ("whsec_..."): this is the only time the secret is shown besides rotating it, so give it to the person who sets up the receiver and do not store it elsewhere.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'The https URL that receives the events, at most 2048 characters.'],
                'event_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 100, 'description' => 'Event names or "module.*", such as ["invoicing.invoice.paid", "invoicing.payment.*"].'],
                'description' => ['type' => 'string', 'description' => 'What the endpoint is for, such as "Accounting sync". Optional.'],
                'version' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'The version of the events to send; an event of another version is not delivered. Optional.'],
            ],
            'required' => ['url', 'event_types'],
            'additionalProperties' => false,
        ];
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function call(array $arguments): ToolOutput
    {
        $url = WebhookToolArguments::required($arguments, 'url');
        $eventTypes = WebhookToolArguments::eventTypes($arguments) ?? [];
        $description = ToolArguments::text($arguments, 'description');
        $version = WebhookToolArguments::integer($arguments, 'version') ?? 1;

        $created = WebhookToolErrors::refuse(fn (): \App\Webhooks\Application\CreatedEndpoint => $this->manage->create($url, $description, $eventTypes, $version));

        return new ToolOutput(
            EndpointView::of($created->endpoint) + ['secret' => $created->secret->reveal()],
            'The endpoint is registered and a ping is queued. The secret is in the data: it is shown only now and when you rotate it.',
        );
    }
}
