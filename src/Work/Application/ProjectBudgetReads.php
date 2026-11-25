<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Identity\Application\UserRepository;
use Foresi\Work\Domain\BudgetCounting;
use Foresi\Work\Domain\ProjectBudgetChange;

/**
 * A project's time budget as whoever sees the project reads it. Used counts
 * the project's time by anyone, not only what the reader sees: a guest sees
 * the same budget and used as the team, but never the changes (P19 of the
 * plan: their notes and sources may hold commercial terms).
 */
final readonly class ProjectBudgetReads
{
    public function __construct(
        private VisibleWork $work,
        private ProjectBudgetStore $budgets,
        private WorkVisibility $visibility,
        private UserRepository $users,
    ) {
    }

    /**
     * @throws WorkRefused unknown for a project the person does not see
     */
    public function budget(string $projectId): ProjectBudget
    {
        $project = $this->work->project($projectId);
        $counting = $project->budgetCounting();
        $changes = null;
        if ($this->visibility->canSeeInternal()) {
            $rows = $this->budgets->changes($project);
            $names = $this->users->namesOf(array_map(static fn (ProjectBudgetChange $change): string => $change->createdBy(), $rows));
            $changes = array_map(static fn (ProjectBudgetChange $change): BudgetChangeRow => new BudgetChangeRow(
                $change->id(),
                $change->effectiveOn(),
                $change->minutes(),
                $change->note(),
                $change->sourceActivityId(),
                $change->createdBy(),
                $names[$change->createdBy()] ?? null,
                $change->createdAt(),
            ), $rows);
        }

        return new ProjectBudget(
            $project->id(),
            $this->budgets->budgetMinutes($project),
            $this->budgets->usedMinutes($project, BudgetCounting::Billable === $counting),
            $counting,
            $this->budgets->estimateMinutes($project),
            $changes,
        );
    }

    /**
     * The budget, or null for a project the person does not see.
     */
    public function find(string $projectId): ?ProjectBudget
    {
        try {
            return $this->budget($projectId);
        } catch (WorkRefused) {
            return null;
        }
    }

    /**
     * Which time uses the budget of a project the person sees.
     *
     * @throws WorkRefused unknown for a project the person does not see
     */
    public function counting(string $projectId): BudgetCounting
    {
        return $this->work->project($projectId)->budgetCounting();
    }
}
