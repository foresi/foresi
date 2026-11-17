<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Instance\Application\Schedule;

/**
 * Runs one operator task.
 */
final readonly class RunOperatorTask
{
    public function __construct(public string $task)
    {
    }
}
