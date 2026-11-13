<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Webhooks\Domain\WebhookEndpoint;

/**
 * The endpoints of the active organisation.
 */
interface WebhookEndpoints
{
    public function find(string $id): ?WebhookEndpoint;

    public function add(WebhookEndpoint $endpoint): void;

    /**
     * Removes the endpoint with its deliveries and their attempts.
     */
    public function remove(WebhookEndpoint $endpoint): void;

    /**
     * Newest first, those before the given id.
     *
     * @return list<WebhookEndpoint>
     */
    public function before(?string $beforeId, int $limit): array;
}
