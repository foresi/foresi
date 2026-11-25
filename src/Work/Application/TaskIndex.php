<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * One page of the list of all tasks: its tasks in order, how many tasks the whole list
 * holds and how many of them each group holds, the groups of later pages too.
 */
final readonly class TaskIndex
{
    /**
     * @param list<TaskRow>      $rows   the page's tasks, in the list's order
     * @param array<string, int> $groups the number of tasks of each group by its key; "" for a task with none, such as no client
     */
    public function __construct(public array $rows, public int $total, public array $groups = [])
    {
    }
}
