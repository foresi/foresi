<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Api;

use App\Webhooks\Application\ManageWebhookEndpoints;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * The body of POST /api/v1/webhook-endpoints/{id}/secret/rotate: how many hours
 * the previous secret still signs, 0 to 168. Without a body, 24.
 */
final readonly class RotateSecretRequest
{
    public function __construct(
        #[SerializedName('keep_previous_hours')]
        public int $keepPreviousHours = ManageWebhookEndpoints::DEFAULT_KEEP_PREVIOUS_HOURS,
    ) {
    }
}
