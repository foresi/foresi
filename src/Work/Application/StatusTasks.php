<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\StatusType;

/**
 * A column of the board: a status with its top-level tasks.
 */
final readonly class StatusTasks
{
    /**
     * @param list<TaskRow> $tasks by position
     */
    public function __construct(public string $id, public string $name, public StatusType $type, public array $tasks)
    {
    }
}
