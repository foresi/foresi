<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port: what another module keeps of a task lets go of it before the task is
 * deleted for good, 30 days after it went to the trash, such as the time logged
 * on it, which stays on the project. It runs in the transaction that deletes
 * the task and its subtasks, before any of them is gone, so a foreign key to
 * a task may restrict its deletion.
 */
#[AutoconfigureTag(self::TAG)]
interface TaskPurge
{
    public const string TAG = 'foresi.work.task_purge';

    /**
     * @param list<string> $taskIds the task and its subtasks
     */
    public function purge(array $taskIds): void;
}
