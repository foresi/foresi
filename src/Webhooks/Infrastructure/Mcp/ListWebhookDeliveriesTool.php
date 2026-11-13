<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Mcp;

use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Webhooks\Application\Query\WebhookDeliveryLog;
use App\Webhooks\Infrastructure\Api\DeliveryView;
use App\Webhooks\Infrastructure\Api\WebhookListQuery;

/**
 * What was sent to one endpoint, listed as GET /api/v1/webhook-endpoints/{id}/deliveries
 * lists it: its deliveries of the last thirty days, newest first. The body of
 * a delivery is never part of it.
 */
final readonly class ListWebhookDeliveriesTool implements ReadTool
{
    public function __construct(private WebhookDeliveryLog $log, private ToolArguments $arguments)
    {
    }

    public function name(): string
    {
        return 'foresi_list_webhook_deliveries';
    }

    public function title(): string
    {
        return 'List webhook deliveries';
    }

    public function description(): string
    {
        return 'Lists the deliveries to one webhook endpoint from the last 30 days, newest first: each has its id, the event it carries, its status (pending, succeeded or failed), how many attempts were made, the last status code and when the next attempt is due. '
            .'Returns {items, next_cursor}. Use it to see whether an endpoint receives its events; to send a finished delivery again, pass its id to foresi_redeliver_webhook.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['endpoint_id' => ToolArguments::idSchema('The id of the endpoint, as foresi_list_webhook_endpoints returns it.')] + ToolArguments::pagingSchema(),
            'required' => ['endpoint_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $endpointId = WebhookToolArguments::required($arguments, 'endpoint_id');
        $query = new WebhookListQuery(ToolArguments::limit($arguments), ToolArguments::text($arguments, 'cursor'));
        $this->arguments->validate($query);
        $slice = WebhookToolErrors::refuse(fn (): \App\Webhooks\Application\Query\DeliverySlice => $this->log->slice($endpointId, ToolArguments::afterId($query->cursor), $query->limit));

        return ToolArguments::page(array_map(DeliveryView::row(...), $slice->rows), $slice->hasMore ? $slice->lastId() : null, 'delivery', 'deliveries');
    }
}
