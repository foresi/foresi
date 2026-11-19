<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

/**
 * A slice of a list as both the REST API and MCP answer it: the items as the
 * API writes them, the id the next slice starts after, and the saved view applied.
 */
final readonly class WorkPage
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>|null  $view
     */
    public function __construct(public array $items, public ?string $nextAfter, public ?array $view = null)
    {
    }
}
