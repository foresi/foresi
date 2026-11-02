<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Schedule;

/**
 * Runs one operator task, with no tenant. Never tenant-scoped: a message that
 * arrives carrying a tenant is refused.
 */
final readonly class RunOperatorTask
{
    public function __construct(public string $task)
    {
    }
}
