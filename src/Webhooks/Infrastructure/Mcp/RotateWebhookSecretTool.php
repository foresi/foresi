<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Mcp;

use App\Shared\Infrastructure\Api\JsonValues;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\WriteTool;
use App\Webhooks\Application\ManageWebhookEndpoints;
use App\Webhooks\Application\Query\WebhookEndpointList;

/**
 * Makes a new signing secret for an endpoint, as POST
 * /api/v1/webhook-endpoints/{id}/secret/rotate does: the old one keeps signing
 * for a while, so the receiver can switch over.
 */
final readonly class RotateWebhookSecretTool implements WriteTool
{
    public function __construct(private ManageWebhookEndpoints $manage, private WebhookEndpointList $list)
    {
    }

    public function name(): string
    {
        return 'foresi_rotate_webhook_secret';
    }

    public function title(): string
    {
        return 'Rotate a webhook secret';
    }

    public function description(): string
    {
        return 'Makes a new signing secret for a webhook endpoint and returns it as {secret, previous_secret_expires_at}. '
            .'The previous secret keeps signing as well for keep_previous_hours (0 to 168, default 24), so the receiver can accept both until it has the new one; after that only the new one signs. '
            .'Rotating cannot be undone. Give the new secret to the person who runs the receiver: it is shown only now.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'endpoint_id' => ToolArguments::idSchema('The id of the endpoint, as foresi_list_webhook_endpoints returns it.'),
                'keep_previous_hours' => ['type' => 'integer', 'minimum' => 0, 'maximum' => ManageWebhookEndpoints::MAX_KEEP_PREVIOUS_HOURS, 'default' => ManageWebhookEndpoints::DEFAULT_KEEP_PREVIOUS_HOURS, 'description' => 'How long the previous secret still signs, in hours.'],
            ],
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
        $hours = WebhookToolArguments::integer($arguments, 'keep_previous_hours') ?? ManageWebhookEndpoints::DEFAULT_KEEP_PREVIOUS_HOURS;

        $data = WebhookToolErrors::refuse(function () use ($id, $hours): array {
            $secret = $this->manage->rotate($id, $hours);

            return ['secret' => $secret->reveal(), 'previous_secret_expires_at' => JsonValues::timestamp($this->list->show($id)->previousExpiresAt())];
        });

        return new ToolOutput($data, 'The new secret is in the data: it is shown only now. The previous one keeps signing until previous_secret_expires_at.');
    }
}
