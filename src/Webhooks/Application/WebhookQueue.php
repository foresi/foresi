<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Webhooks\Domain\WebhookEndpoint;

/**
 * Queues the attempts a person asks for: a ping of an endpoint, stored as a
 * delivery of its own, and one more attempt of a stored delivery. Each carries
 * the id of the attempt it records, so it is made once.
 */
interface WebhookQueue
{
    public function ping(WebhookEndpoint $endpoint, \DateTimeImmutable $now): QueuedAttempt;

    public function redeliver(string $tenantId, string $deliveryId): QueuedAttempt;
}
