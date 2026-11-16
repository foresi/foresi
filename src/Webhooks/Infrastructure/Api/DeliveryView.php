<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Api;

use App\Shared\Infrastructure\Api\JsonValues;
use App\Webhooks\Application\Query\AttemptRow;
use App\Webhooks\Application\Query\DeliveryDetail;
use App\Webhooks\Application\Query\DeliveryRow;

/**
 * A delivery as the API and MCP show it, and with its attempts in the detail.
 * Neither the body sent nor anything the receiver answered is part of it.
 */
final class DeliveryView
{
    /**
     * @return array<string, mixed>
     */
    public static function row(DeliveryRow $delivery): array
    {
        return [
            'id' => $delivery->id,
            'endpoint_id' => $delivery->endpointId,
            'event_id' => $delivery->eventId,
            'event_type' => $delivery->eventType,
            'kind' => $delivery->kind,
            'status' => $delivery->status,
            'attempt_count' => $delivery->attempts,
            'last_status_code' => $delivery->lastStatusCode,
            'next_attempt_at' => JsonValues::timestamp($delivery->nextAttemptAt),
            'created_at' => JsonValues::timestamp($delivery->createdAt),
            'finished_at' => JsonValues::timestamp($delivery->finishedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(DeliveryDetail $detail): array
    {
        return self::row($detail->delivery) + ['attempts' => array_map(self::attempt(...), $detail->attempts)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attempt(AttemptRow $attempt): array
    {
        return [
            'id' => $attempt->id,
            'attempted_at' => JsonValues::timestamp($attempt->attemptedAt),
            'duration_ms' => $attempt->durationMs,
            'status_code' => $attempt->statusCode,
            'error' => $attempt->error,
            'trigger' => $attempt->trigger,
        ];
    }
}
