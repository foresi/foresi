<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * The read side of time, under the same rule as the work it is logged on: an
 * entry is seen with its task, or with the whole project when it has no task.
 * Time on a task in the trash is left out, as the task is.
 */
interface TimeQueries
{
    /**
     * Null when the person does not see the task.
     */
    public function ofTask(string $taskId): ?TaskTime;

    /**
     * The week of the day for the person, the signed-in one by default. Only an
     * owner reads the week of someone else; null otherwise, and for no one.
     */
    public function week(\DateTimeImmutable $day, ?string $membershipId = null): ?TimeWeek;

    /**
     * The timer the signed-in person runs, if any, whatever they see now.
     */
    public function running(): ?TimeEntryRow;
}
