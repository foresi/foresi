<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Work\Domain\Priority;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\TaskLabel;
use App\Work\Domain\TaskWatcher;
use Psr\Clock\ClockInterface;

/**
 * Creating a task and changing it one property at a time, as the task page
 * saves each field on its own. The person who creates a task and the person
 * responsible for it follow it, as in ClickUp and Asana.
 *
 * Deleting puts a task in the trash with its subtasks, at one moment, as one
 * step; restoring brings back what went there at that moment. A subtask does
 * not come back without its task: restoring it restores the task too.
 */
final readonly class ManageTasks
{
    /** The moment of a deletion as the database keeps it, to the second. */
    private const string MOMENT = 'Y-m-d H:i:s';

    public function __construct(
        private Projects $projects,
        private Tasks $tasks,
        private Labels $labels,
        private VisibleWork $work,
        private People $people,
        private AuditActor $actor,
        private ClockInterface $clock,
        private WorkUnit $unit,
        private TrashPermission $trash,
    ) {
    }

    /**
     * A task at the end of the project. Without a status it starts in the first
     * open one; a subtask sits under a task of the same project that is not a
     * subtask itself.
     *
     * @throws WorkRefused
     */
    public function create(
        string $projectId,
        string $title,
        ?string $sectionId = null,
        ?string $parentId = null,
        ?string $statusId = null,
        ?string $responsibleId = null,
        ?\DateTimeImmutable $dueOn = null,
        Priority $priority = Priority::None,
    ): Task {
        $project = $this->work->project($projectId);
        if ($project->isArchived()) {
            throw new WorkRefused('archived');
        }
        $userId = $this->actor->userId() ?? throw new WorkRefused('not_allowed');
        $section = null === $sectionId ? null : $this->work->section($sectionId);
        $status = null === $statusId ? $this->firstOpen($project) : $this->work->status($statusId);
        $parent = null === $parentId ? null : $this->work->task($parentId);
        if ($parent instanceof Task && ($parent->isSubtask() || $parent->project()->id() !== $project->id())) {
            throw new WorkRefused('parent');
        }
        if ($section instanceof Section && $section->project()->id() !== $project->id()) {
            throw new WorkRefused('unknown');
        }
        if ($status->project()->id() !== $project->id()) {
            throw new WorkRefused('unknown');
        }
        $responsible = null === $responsibleId ? null : $this->activeMembership($responsibleId, 'responsible');
        $now = $this->clock->now();
        $task = WorkRefused::unlessNamed(fn (): Task => new Task($project, $title, $status, $this->tasks->nextPosition($project), $userId, $now, $section, $parent));
        $task->assign($responsible);
        $task->dueOn($dueOn);
        $task->prioritise($priority);

        return $this->unit->run(function () use ($task, $userId, $responsible): Task {
            $this->tasks->add($task);
            $watchers = [];
            $creator = $this->people->membershipOf($userId);
            foreach ([$creator, $responsible] as $membership) {
                if ($membership instanceof Membership && $membership->isActive() && !isset($watchers[$membership->id()])) {
                    $watchers[$membership->id()] = true;
                    $this->tasks->add(new TaskWatcher($task, $membership));
                }
            }

            return $task;
        });
    }

    /**
     * @throws WorkRefused
     */
    public function rename(string $taskId, string $title): void
    {
        $task = $this->work->task($taskId);
        WorkRefused::unlessNamed(static fn () => $task->rename($title));
        $this->unit->run(static fn (): null => null);
    }

    /**
     * @throws WorkRefused
     */
    public function describe(string $taskId, string $description): void
    {
        $task = $this->work->task($taskId);
        $this->unit->run(static fn () => $task->describe($description));
    }

    /**
     * Gives the task to a person, who then follows it; null leaves it to nobody.
     *
     * @throws WorkRefused
     */
    public function assign(string $taskId, ?string $membershipId): void
    {
        $task = $this->work->task($taskId);
        $responsible = null === $membershipId ? null : $this->activeMembership($membershipId, 'responsible');
        $this->unit->run(function () use ($task, $responsible): void {
            $task->assign($responsible);
            if ($responsible instanceof Membership) {
                $this->addWatcher($task, $responsible);
            }
        });
    }

    /**
     * @throws WorkRefused
     */
    public function setDue(string $taskId, ?\DateTimeImmutable $dueOn): void
    {
        $task = $this->work->task($taskId);
        $this->unit->run(static fn () => $task->dueOn($dueOn));
    }

    /**
     * @throws WorkRefused
     */
    public function prioritise(string $taskId, Priority $priority): void
    {
        $task = $this->work->task($taskId);
        $this->unit->run(static fn () => $task->prioritise($priority));
    }

    /**
     * @param string|null $sectionId null for no section
     *
     * @throws WorkRefused
     */
    public function moveToSection(string $taskId, ?string $sectionId): void
    {
        $task = $this->work->task($taskId);
        $section = null === $sectionId ? null : $this->work->section($sectionId);
        if ($section instanceof Section && $section->project()->id() !== $task->project()->id()) {
            throw new WorkRefused('unknown');
        }
        $this->unit->run(static fn () => $task->moveToSection($section));
    }

    /**
     * Changes the status from the task page; the task keeps its place in the project.
     *
     * @throws WorkRefused
     */
    public function changeStatus(string $taskId, string $statusId): void
    {
        $task = $this->work->task($taskId);
        $status = $this->statusOf($task, $statusId);
        $this->unit->run(fn () => $task->moveToStatus($status, $this->clock->now()));
    }

    /**
     * Drops the task on the board: into a status column, before a task of the
     * column, or at its end when none is given. Tasks of the project at the
     * same level take dense positions again, so the order sticks in the
     * column and in the list alike.
     *
     * @throws WorkRefused
     */
    public function move(string $taskId, string $statusId, ?string $beforeTaskId = null): void
    {
        $task = $this->work->task($taskId);
        $status = $this->statusOf($task, $statusId);
        $before = null === $beforeTaskId ? null : $this->work->task($beforeTaskId);
        if ($before instanceof Task && ($before->id() === $task->id() || $before->status()->id() !== $status->id() || $before->parent()?->id() !== $task->parent()?->id() || $before->project()->id() !== $task->project()->id())) {
            throw new WorkRefused('unknown');
        }
        $siblings = array_values(array_filter($this->tasks->siblings($task->project(), $task->parent()), static fn (Task $other): bool => $other->id() !== $task->id()));
        $index = \count($siblings);
        foreach ($siblings as $at => $sibling) {
            if ($before instanceof Task) {
                if ($sibling->id() === $before->id()) {
                    $index = $at;
                    break;
                }
            } elseif ($sibling->status()->id() === $status->id()) {
                $index = $at + 1;
            }
        }

        $this->unit->run(function () use ($task, $status, $siblings, $index): void {
            Positions::place($siblings, $task, $index);
            if ($task->status()->id() !== $status->id()) {
                $task->moveToStatus($status, $this->clock->now());
            }
        });
    }

    /**
     * The labels of the task become exactly these.
     *
     * @param list<string> $labelIds
     *
     * @throws WorkRefused
     */
    public function setLabels(string $taskId, array $labelIds): void
    {
        $task = $this->work->task($taskId);
        $wanted = [];
        foreach ($labelIds as $labelId) {
            $label = $this->labels->label($labelId) ?? throw new WorkRefused('unknown');
            $wanted[$label->id()] = $label;
        }
        $this->unit->run(function () use ($task, $wanted): void {
            foreach ($this->tasks->labels($task) as $taskLabel) {
                if (isset($wanted[$taskLabel->label()->id()])) {
                    unset($wanted[$taskLabel->label()->id()]);
                } else {
                    $this->tasks->remove($taskLabel);
                }
            }
            foreach ($wanted as $label) {
                $this->tasks->add(new TaskLabel($task, $label));
            }
        });
    }

    /**
     * Following twice is following once.
     *
     * @throws WorkRefused
     */
    public function watch(string $taskId, string $membershipId): void
    {
        $task = $this->work->task($taskId);
        $membership = $this->activeMembership($membershipId, 'watcher');
        $this->unit->run(fn () => $this->addWatcher($task, $membership));
    }

    /**
     * @throws WorkRefused
     */
    public function unwatch(string $taskId, string $membershipId): void
    {
        $task = $this->work->task($taskId);
        $this->unit->run(function () use ($task, $membershipId): void {
            foreach ($this->tasks->watchers($task) as $watcher) {
                if ($watcher->membership()->id() === $membershipId) {
                    $this->tasks->remove($watcher);
                }
            }
        });
    }

    /**
     * Puts the task and its subtasks in the trash; a subtask there already
     * keeps its own moment.
     *
     * @throws WorkRefused
     */
    public function delete(string $taskId): Task
    {
        $task = $this->work->task($taskId);
        if (!$this->trash->allows($task)) {
            throw new WorkRefused('task_not_yours');
        }
        $userId = $this->actor->userId() ?? throw new WorkRefused('not_allowed');
        $now = $this->clock->now();

        return $this->unit->run(function () use ($task, $userId, $now): Task {
            $task->delete($userId, $now);
            foreach ($this->tasks->subtasksOf($task) as $subtask) {
                $subtask->delete($userId, $now);
            }

            return $task;
        });
    }

    /**
     * Takes the task out of the trash with the subtasks that went there with
     * it. A subtask whose task is in the trash brings the task back, and the
     * subtasks that went with that, when the person may restore that task too.
     * A task out of the trash already stays as it is.
     *
     * @throws WorkRefused
     */
    public function restore(string $taskId): Task
    {
        $task = $this->tasks->task($taskId) instanceof Task ? $this->work->task($taskId) : $this->work->trashed($taskId);
        if (!$this->trash->allows($task)) {
            throw new WorkRefused('task_not_yours');
        }
        if (!$task->isDeleted()) {
            return $task;
        }
        $parent = $task->parent();
        if ($parent instanceof Task && $parent->isDeleted() && !$this->trash->allows($parent)) {
            throw new WorkRefused('parent_in_trash');
        }

        return $this->unit->run(function () use ($task): Task {
            $parent = $task->parent();
            if ($parent instanceof Task && $parent->isDeleted()) {
                $this->restoreWithSubtasks($parent);
            }
            $this->restoreWithSubtasks($task);

            return $task;
        });
    }

    private function restoreWithSubtasks(Task $task): void
    {
        $moment = $task->deletedAt()?->format(self::MOMENT);
        if (null === $moment) {
            return;
        }
        $task->restore();
        foreach ($this->tasks->subtasksOf($task) as $subtask) {
            if ($subtask->deletedAt()?->format(self::MOMENT) === $moment) {
                $subtask->restore();
            }
        }
    }

    private function addWatcher(Task $task, Membership $membership): void
    {
        foreach ($this->tasks->watchers($task) as $watcher) {
            if ($watcher->membership()->id() === $membership->id()) {
                return;
            }
        }
        $this->tasks->add(new TaskWatcher($task, $membership));
    }

    private function activeMembership(string $id, string $reason): Membership
    {
        $membership = $this->people->membership($id);
        if (!$membership instanceof Membership || !$membership->isActive()) {
            throw new WorkRefused($reason);
        }

        return $membership;
    }

    private function statusOf(Task $task, string $statusId): ProjectStatus
    {
        $status = $this->work->status($statusId);
        if ($status->project()->id() !== $task->project()->id()) {
            throw new WorkRefused('unknown');
        }

        return $status;
    }

    private function firstOpen(Project $project): ProjectStatus
    {
        $statuses = $this->projects->statuses($project);
        foreach ($statuses as $status) {
            if (StatusType::Open === $status->type()) {
                return $status;
            }
        }

        return $statuses[0] ?? throw new WorkRefused('unknown');
    }
}
