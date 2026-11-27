<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\Task;

/**
 * The recurring tasks of the organisation and the tasks each one created.
 */
interface RecurringTasks
{
    public function add(RecurringTask $recurringTask): void;

    public function find(string $id): ?RecurringTask;

    /**
     * The recurring task as stored now, locked to the end of the caller's
     * transaction, so two runs never create the same date. The session starts
     * over, so whatever was loaded before is detached; call it before any change.
     */
    public function lock(string $id): ?RecurringTask;

    /**
     * The active ones whose next date is $until or earlier, by id.
     *
     * @return list<string>
     */
    public function due(\DateTimeImmutable $until): array;

    /**
     * Every recurring task, the active ones first, then by their next date and title.
     *
     * @return list<RecurringTask>
     */
    public function all(): array;

    /**
     * The tasks it created, out of the trash too, the latest date first.
     *
     * @return list<Task>
     */
    public function tasksOf(RecurringTask $recurringTask, int $limit): array;
}
