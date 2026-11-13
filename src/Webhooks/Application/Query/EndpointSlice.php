<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application\Query;

use App\Webhooks\Domain\WebhookEndpoint;

/**
 * A slice of the endpoints the caller may manage, newest first, and whether
 * more follows it. The next slice starts after the id of the last one.
 */
final readonly class EndpointSlice
{
    /**
     * @param list<WebhookEndpoint> $endpoints
     */
    public function __construct(public array $endpoints, public bool $hasMore)
    {
    }

    public function lastId(): ?string
    {
        return [] === $this->endpoints ? null : $this->endpoints[\count($this->endpoints) - 1]->id();
    }
}
