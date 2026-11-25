<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Work\Contract\Event\ProjectBudgetChanged;
use Foresi\Work\Domain\BudgetCounting;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectBudgetChange;
use Psr\Clock\ClockInterface;

/**
 * Changing a project's time budget: adding a dated change, removing a wrong
 * one and choosing which time uses it. Whoever may edit the project may
 * (VisibleWork::managedProject, as ManageProjects::edit). The budget never
 * goes below zero; going over it by logging time is shown, never refused.
 */
final readonly class ProjectBudgets
{
    public function __construct(
        private VisibleWork $work,
        private ProjectBudgetStore $budgets,
        private BudgetSourceCheck $sources,
        private AuditActor $actor,
        private ClockInterface $clock,
        private InstanceClock $instanceClock,
        private EventRecorder $events,
        private WorkUnit $unit,
    ) {
    }

    /**
     * @param int                     $minutes     plus or minus, not zero
     * @param \DateTimeImmutable|null $effectiveOn the date of the change in its log; today when null
     *
     * @throws WorkRefused
     */
    public function add(string $projectId, int $minutes, ?\DateTimeImmutable $effectiveOn = null, string $note = '', ?string $sourceActivityId = null): ProjectBudgetChange
    {
        $userId = $this->actor->userId() ?? throw new WorkRefused('not_allowed');
        $project = $this->editable($projectId);
        if (0 === $minutes) {
            throw new WorkRefused('hours_zero');
        }
        if (abs($minutes) > ProjectBudgetChange::MAX_MINUTES) {
            throw new WorkRefused('hours_invalid');
        }
        $note = trim($note);
        if (mb_strlen($note) > ProjectBudgetChange::MAX_NOTE_LENGTH) {
            throw new WorkRefused('budget_note');
        }
        $sourceActivityId = null === $sourceActivityId || '' === $sourceActivityId ? null : $sourceActivityId;
        if (null !== $sourceActivityId && !$this->sources->isOnProject($sourceActivityId, $project->id())) {
            throw new WorkRefused('source_not_on_project');
        }
        $change = new ProjectBudgetChange($project, $effectiveOn ?? $this->instanceClock->today(), $minutes, $note, $sourceActivityId, $userId, $this->clock->now());

        return $this->unit->run(function () use ($project, $change): ProjectBudgetChange {
            if ($this->budgets->lockedMinutes($project) + $change->minutes() < 0) {
                throw new WorkRefused('budget_below_zero');
            }
            $this->budgets->add($change);
            $this->events->record(new ProjectBudgetChanged($project->id(), $change->id(), ProjectBudgetChanged::ADDED));

            return $change;
        });
    }

    /**
     * Removes a wrong change; the audit trail keeps it.
     *
     * @throws WorkRefused
     */
    public function remove(string $projectId, string $changeId): void
    {
        $project = $this->editable($projectId);
        $change = $this->budgets->change($changeId);
        if (!$change instanceof ProjectBudgetChange || $change->project()->id() !== $project->id()) {
            throw new WorkRefused('unknown');
        }
        $this->unit->run(function () use ($project, $change, $changeId): void {
            $minutes = $this->budgets->lockedMinutes($project);
            // Removed meanwhile by someone else, who held the lock first.
            if (!$this->budgets->change($changeId) instanceof ProjectBudgetChange) {
                throw new WorkRefused('unknown');
            }
            if ($minutes - $change->minutes() < 0) {
                throw new WorkRefused('budget_below_zero');
            }
            $this->events->record(new ProjectBudgetChanged($project->id(), $change->id(), ProjectBudgetChanged::REMOVED));
            $this->budgets->remove($change);
        });
    }

    /**
     * Whether all the project's time uses its budget, or the billable time only.
     *
     * @throws WorkRefused
     */
    public function count(string $projectId, BudgetCounting $counting): void
    {
        $project = $this->editable($projectId);
        if ($project->budgetCounting() === $counting) {
            return;
        }
        $this->unit->run(function () use ($project, $counting): void {
            $project->countBudget($counting);
            $this->events->record(new ProjectBudgetChanged($project->id(), null, ProjectBudgetChanged::COUNTING));
        });
    }

    private function editable(string $projectId): Project
    {
        $project = $this->work->managedProject($projectId);
        if ($project->isArchived()) {
            throw new WorkRefused('archived');
        }

        return $project;
    }
}
