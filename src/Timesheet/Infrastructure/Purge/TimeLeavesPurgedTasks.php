<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Purge;

use Foresi\Timesheet\Application\TimeEntries;
use Foresi\Work\Contract\TaskPurge;

/**
 * The time logged on a task that is deleted for good stays, on its project
 * without a task: it was worked, and may be billed. It lets go of the task
 * before Work deletes it, as the time's foreign key to the task restricts it.
 */
final readonly class TimeLeavesPurgedTasks implements TaskPurge
{
    public function __construct(private TimeEntries $entries)
    {
    }

    public function purge(array $taskIds): void
    {
        foreach ($this->entries->onTasks($taskIds) as $entry) {
            $entry->leaveTask();
        }
    }
}
