<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * Port: the minutes logged on tasks, which the lists of tasks show beside the
 * estimate to whom manages work. Work declares it and keeps no time; the
 * module that tracks time answers it, and without that module the lists show
 * no time logged.
 */
interface TaskTimes
{
    /**
     * @param list<string> $taskIds
     *
     * @return array<string, int> minutes by task id: the time in the trash and a running timer left out; a task without time is left out
     */
    public function minutes(array $taskIds): array;
}
