<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectBudgetChange;

/**
 * The time budgets of projects: their changes, and the time and the task
 * estimates they are set against.
 */
interface ProjectBudgetStore
{
    public function change(string $id): ?ProjectBudgetChange;

    /**
     * @return list<ProjectBudgetChange> the latest date first, the latest entered first on a day
     */
    public function changes(Project $project): array;

    /**
     * The sum of the project's changes in minutes, or null for a project without any.
     */
    public function budgetMinutes(Project $project): ?int;

    /**
     * Holds the project until the end of the transaction, so that two changes
     * at once each see the other's sum, and answers the sum of its changes in
     * minutes, 0 without any.
     */
    public function lockedMinutes(Project $project): int;

    /**
     * The minutes logged on the project, by anyone: not in the trash, not on a
     * task in the trash, and no running timer.
     */
    public function usedMinutes(Project $project, bool $billableOnly): int;

    /**
     * The sum of the estimates of the project's tasks not in the trash.
     */
    public function estimateMinutes(Project $project): int;

    public function add(ProjectBudgetChange $change): void;

    public function remove(ProjectBudgetChange $change): void;
}
