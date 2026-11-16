<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Mcp;

use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Webhooks\Application\Query\WebhookEndpointList;
use App\Webhooks\Infrastructure\Api\EndpointView;
use App\Webhooks\Infrastructure\Api\WebhookListQuery;

/**
 * The webhook endpoints of the organisation, listed as GET /api/v1/webhook-endpoints
 * lists them: those the token's person may manage, newest first, never with a secret.
 */
final readonly class ListWebhookEndpointsTool implements ReadTool
{
    public function __construct(private WebhookEndpointList $list, private ToolArguments $arguments)
    {
    }

    public function name(): string
    {
        return 'foresi_list_webhook_endpoints';
    }

    public function title(): string
    {
        return 'List webhook endpoints';
    }

    public function description(): string
    {
        return 'Lists the webhook endpoints of the organisation that you may manage, newest first: each has its id, url, description, event_types, version, whether it is active, the disabled_reason when it is not, and when the previous secret stops signing. '
            .'Returns {items, next_cursor}; secrets are never part of it. '
            .'Use the id with the other webhook tools.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ToolArguments::pagingSchema(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        $query = new WebhookListQuery(ToolArguments::limit($arguments), ToolArguments::text($arguments, 'cursor'));
        $this->arguments->validate($query);
        $slice = $this->list->slice(ToolArguments::afterId($query->cursor), $query->limit);

        return ToolArguments::page(array_map(EndpointView::of(...), $slice->endpoints), $slice->hasMore ? $slice->lastId() : null, 'webhook endpoint', 'webhook endpoints');
    }
}
