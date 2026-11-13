<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Api;

use App\Shared\Infrastructure\Api\JsonValues;
use App\Webhooks\Domain\WebhookEndpoint;

/**
 * An endpoint as the API and MCP show it. It never holds a secret: those are
 * added only to the answers of create, rotate and reveal.
 */
final class EndpointView
{
    /**
     * @return array<string, mixed>
     */
    public static function of(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id(),
            'url' => $endpoint->url()->value,
            'description' => $endpoint->description(),
            'event_types' => $endpoint->eventTypes()->patterns(),
            'version' => $endpoint->version(),
            'active' => $endpoint->isActive(),
            'disabled_reason' => $endpoint->disabledReason()?->value,
            'owner_id' => $endpoint->ownerId(),
            'previous_secret_expires_at' => JsonValues::timestamp($endpoint->previousExpiresAt()),
            'created_at' => JsonValues::timestamp($endpoint->createdAt()),
            'updated_at' => JsonValues::timestamp($endpoint->updatedAt()),
        ];
    }
}
