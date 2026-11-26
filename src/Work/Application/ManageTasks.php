<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Application\Field\CustomFieldWrites;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Work\Contract\Event\TaskCompleted;
use Foresi\Work\Contract\Event\TaskCreated;
use Foresi\Work\Contract\Event\TaskTrashed;
use Foresi\Work\Contract\Event\TaskUpdated;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskLabel;
use Foresi\Work\Domain\TaskWatcher;
use Psr\Clock\ClockInterface;

/**
 * Creating a task and changing it one property at a time, as the task page
 * saves each field on its own. The person who creates a task and the person
 * responsible for it follow it, as in ClickUp and Asana. Whoever becomes
 * responsible hears of it, and the watchers of a task hear of a change of its
 * status, through the Notifier in the same unit of work.
 *
 * Deleting puts a task in the trash with its subtasks, at one moment, as one
 * step; restoring brings back what went there at that moment. A subtask does
 * not come back without its task: restoring it restores the task too.
 *
 * Only someone who sees a task is responsible for it or follows it.
 *
 * Each change that changes something records its event in the same unit
 * (ADR 0030): created, updated with the fields changed, completed when the
 * task reaches a finished status, trashed for the task a person deleted.
 *
 * The custom fields of a task (ADR 0045) are written in the same unit: a new
 * task with each required one, and a change one field at a time, as the
 * sidebar saves it, with its key in work.task.updated. A guest writes only the
 * fields open to guests.
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
        private WorkVisibility $visibility,
        private People $people,
        private AuditActor $actor,
        private ClockInterface $clock,
        private WorkUnit $unit,
        private TrashPermission $trash,
        private Notifier $notifier,
        private EventRecorder $events,
        private CustomFieldWrites $customFields,
    ) {
    }

    /**
     * A task at the end of the project. Without a status it starts in the first
     * open one; a subtask sits under a task of the same project that is not a
     * subtask itself.
     *
     * @param array<string, mixed>|null $customFields the custom fields by key, each required one with a value; null when the caller offers none
     *
     * @throws WorkRefused
     * @throws FieldRefused nothing is stored
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
        ?array $customFields = null,
    ): Task {
        // A subtask needs only its task to be seen, not the whole project.
        $parent = null === $parentId ? null : $this->work->task($parentId);
        $project = $parent instanceof Task && $parent->project()->id() === $projectId ? $parent->project() : $this->work->project($projectId);
        if ($project->isArchived()) {
            throw new WorkRefused('archived');
        }
        $userId = $this->actor->userId() ?? throw new WorkRefused('not_allowed');
        // A subtask sits in the section of its task, which a person shown only that task may not see.
        $section = match (true) {
            null === $sectionId => null,
            $parent instanceof Task && $parent->section()?->id() === $sectionId => $parent->section(),
            default => $this->work->section($sectionId),
        };
        $status = null === $statusId ? $this->firstOpen($project) : $this->work->status($statusId);
        if ($parent instanceof Task && ($parent->isSubtask() || $parent->project()->id() !== $project->id())) {
            throw new WorkRefused('parent');
        }
        if ($section instanceof Section && $section->project()->id() !== $project->id()) {
            throw new WorkRefused('unknown');
        }
        if ($status->project()->id() !== $project->id()) {
            throw new WorkRefused('unknown');
        }
        // A task goes where the person sees the section, or the whole project for none.
        if (!$parent instanceof Task) {
            $this->work->assertTaskIn($project, $section);
        }
        $now = $this->clock->now();
        $task = WorkRefused::unlessNamed(fn (): Task => new Task($project, $title, $status, $this->tasks->nextPosition($project), $userId, $now, $section, $parent));
        $responsible = null === $responsibleId ? null : $this->seeingMembership($responsibleId, $task, 'responsible');
        $task->assign($responsible);
        $task->dueOn($dueOn);
        $task->prioritise($priority);

        return $this->unit->run(function () use ($task, $userId, $responsible, $customFields): Task {
            $this->tasks->add($task);
            if (null !== $customFields) {
                $this->customFields->write(TaskExtension::RECORD, $task->id(), $customFields, true);
            }
            $watchers = [];
            $creator = $this->people->membershipOf($userId);
            foreach ([$creator, $responsible] as $membership) {
                if ($membership instanceof Membership && $membership->isActive() && !isset($watchers[$membership->id()])) {
                    $watchers[$membership->id()] = true;
                    $this->tasks->add(new TaskWatcher($task, $membership));
                }
            }
            if ($responsible instanceof Membership) {
                $this->notifier->assigned($task, $responsible);
            }
            $this->events->record(new TaskCreated($task->id(), $task->project()->id(), $task->parent()?->id()));

            return $task;
        });
    }

    /**
     * @throws WorkRefused
     */
    public function rename(string $taskId, string $title): void
    {
        $task = $this->work->task($taskId);
        $before = $task->title();
        WorkRefused::unlessNamed(static fn () => $task->rename($title));
        $this->unit->run(fn () => $this->updated($task, $before !== $task->title(), TaskUpdated::TITLE));
    }

    /**
     * @throws WorkRefused
     */
    public function describe(string $taskId, string $description): void
    {
        $task = $this->work->task($taskId);
        $this->unit->run(function () use ($task, $description): void {
            $before = $task->description();
            $task->describe($description);
            $this->updated($task, $before !== $task->description(), TaskUpdated::DESCRIPTION);
        });
    }

    /**
     * Gives the task to a person, who then follows it; null leaves it to nobody.
     *
     * @throws WorkRefused
     */
    public function assign(string $taskId, ?string $membershipId): void
    {
        $task = $this->work->task($taskId);
        $responsible = null === $membershipId ? null : $this->seeingMembership($membershipId, $task, 'responsible');
        $this->unit->run(function () use ($task, $responsible): void {
            $before = $task->responsible();
            $task->assign($responsible);
            if ($responsible instanceof Membership) {
                $this->addWatcher($task, $responsible);
                if ($before?->id() !== $responsible->id()) {
                    $this->notifier->assigned($task, $responsible);
                }
            }
            $this->updated($task, $before?->id() !== $responsible?->id(), TaskUpdated::RESPONSIBLE);
        });
    }

    /**
     * @throws WorkRefused
     */
    public function setDue(string $taskId, ?\DateTimeImmutable $dueOn): void
    {
        $task = $this->work->task($taskId);
        $this->unit->run(function () use ($task, $dueOn): void {
            $before = $task->dueDate()?->format('Y-m-d');
            $task->dueOn($dueOn);
            $this->updated($task, $before !== $task->dueDate()?->format('Y-m-d'), TaskUpdated::DUE_ON);
        });
    }

    /**
     * One custom field of the task, null clearing it.
     *
     * @throws WorkRefused
     * @throws FieldRefused for a field the task has not, one hidden from a guest, or a value its kind does not take
     */
    public function setCustomField(string $taskId, string $key, mixed $value): void
    {
        $this->setCustomFields($taskId, [$key => $value]);
    }

    /**
     * Custom fields of the task by key, null clearing one, in one change: one
     * work.task.updated names every key whose value changed.
     *
     * @param array<string, mixed> $values by field key, as Fields::write() takes them
     *
     * @throws WorkRefused
     * @throws FieldRefused for a field the task has not, one hidden from a guest, or a value its kind does not take
     */
    public function setCustomFields(string $taskId, array $values): void
    {
        $task = $this->work->task($taskId);
        if ([] === $values) {
            return;
        }
        $this->unit->run(function () use ($task, $values): void {
            $changed = $this->customFields->write(TaskExtension::RECORD, $task->id(), $values, false);
            if ([] !== $changed) {
                $this->events->record(new TaskUpdated($task->id(), $task->project()->id(), [], $changed));
            }
        });
    }

    /**
     * @throws WorkRefused
     */
    public function prioritise(string $taskId, Priority $priority): void
    {
        $task = $this->work->task($taskId);
        $this->unit->run(function () use ($task, $priority): void {
            $before = $task->priority();
            $task->prioritise($priority);
            $this->updated($task, $before !== $task->priority(), TaskUpdated::PRIORITY);
        });
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
        $this->work->assertTaskIn($task->project(), $section);
        $this->unit->run(function () use ($task, $section): void {
            $before = $task->section()?->id();
            $task->moveToSection($section);
            $this->updated($task, $before !== $task->section()?->id(), TaskUpdated::SECTION);
        });
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
        $this->unit->run(function () use ($task, $status): void {
            if ($task->status()->id() !== $status->id()) {
                $this->moveToStatus($task, $status);
            }
        });
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
                $this->moveToStatus($task, $status);
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
            $changed = false;
            foreach ($this->tasks->labels($task) as $taskLabel) {
                // A label in the trash is not offered, so the task keeps it for its restore.
                if ($taskLabel->label()->isDeleted()) {
                    continue;
                }
                if (isset($wanted[$taskLabel->label()->id()])) {
                    unset($wanted[$taskLabel->label()->id()]);
                } else {
                    $this->tasks->remove($taskLabel);
                    $changed = true;
                }
            }
            foreach ($wanted as $label) {
                $this->tasks->add(new TaskLabel($task, $label));
                $changed = true;
            }
            $this->updated($task, $changed, TaskUpdated::LABELS);
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
        $membership = $this->seeingMembership($membershipId, $task, 'watcher');
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
            $this->events->record(new TaskTrashed($task->id(), $task->project()->id()));

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

    /**
     * A new status, heard by the watchers, recorded as a change and, when it
     * finishes the task, as its completion.
     */
    private function moveToStatus(Task $task, ProjectStatus $status): void
    {
        $finished = $task->status()->type()->isFinished();
        $task->moveToStatus($status, $this->clock->now());
        $this->notifier->statusChanged($task);
        $this->updated($task, true, TaskUpdated::STATUS);
        if (!$finished && $status->type()->isFinished()) {
            $this->events->record(new TaskCompleted($task->id(), $task->project()->id()));
        }
    }

    private function updated(Task $task, bool $changed, string $field): void
    {
        if ($changed) {
            $this->events->record(new TaskUpdated($task->id(), $task->project()->id(), [$field]));
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

    /**
     * An active person who sees the task: only they can be responsible for it or follow it.
     */
    private function seeingMembership(string $id, Task $task, string $reason): Membership
    {
        $membership = $this->people->membership($id);
        if (!$membership instanceof Membership || !$this->visibility->canMembershipSeeTask($membership, $task)) {
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
