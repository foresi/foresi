<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Domain\Schedule\PeriodText;
use Foresi\Work\Contract\Event\RecurringTaskEnded;
use Foresi\Work\Contract\Event\TaskCreated;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\Label;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\RecurringTaskState;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskLabel;
use Foresi\Work\Domain\TaskWatcher;
use Foresi\Work\Domain\WorkShare;
use Psr\Clock\ClockInterface;

/**
 * Creates the task of the next date of a recurring task, at most once however
 * often it is called and by however many workers: the recurring task is locked,
 * and a task names its recurring task and date uniquely. The task is an ordinary
 * one of the project, at its end, in its first open status, in the name of
 * whoever set the recurring task up. It gets the title and description with the
 * period variables of its date, the section, responsible, priority, estimate,
 * private flag, labels, watchers and custom fields, and the subtasks as new open
 * ones; due on its due date. Nothing of an earlier task is copied: no comments,
 * attachments or time.
 *
 * What no longer holds is left out rather than stopping the slot: a label in the
 * trash, a person no longer active or who would not see the task. A private task
 * is shared with whoever set it up, its responsible and its watchers, so they see
 * it. An archived project or custom field values its fields no longer take refuse
 * the date (OccurrenceRefused), which stays next, so the run tries again.
 */
final readonly class CreateRecurringOccurrence
{
    public function __construct(
        private RecurringTasks $recurring,
        private Tasks $tasks,
        private Projects $projects,
        private Labels $labels,
        private People $people,
        private WorkVisibility $visibility,
        private WorkShares $shares,
        private Fields $fields,
        private EventRecorder $events,
        private WorkUnit $unit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param \DateTimeImmutable $today the instance's day the run works for
     *
     * @return string|null the id of the task created; null when there is none to create by $today
     *
     * @throws OccurrenceRefused nothing is stored
     */
    public function create(string $recurringTaskId, \DateTimeImmutable $today): ?string
    {
        return $this->unit->run(function () use ($recurringTaskId, $today): ?string {
            $recurring = $this->recurring->lock($recurringTaskId);
            if (!$recurring instanceof RecurringTask || !$recurring->isDue($today)) {
                return null;
            }
            $occurrence = $recurring->nextOccurrence() ?? throw new \LogicException('A due recurring task has a next date.');
            $task = $this->task($recurring, $occurrence);
            $recurring->advance($occurrence);
            if (RecurringTaskState::Ended === $recurring->state()) {
                $this->events->record(new RecurringTaskEnded($recurring->id(), $recurring->project()->id()));
            }

            return $task->id();
        });
    }

    /**
     * @throws OccurrenceRefused
     */
    private function task(RecurringTask $recurring, \DateTimeImmutable $occurrence): Task
    {
        $project = $recurring->project();
        if ($project->isArchived()) {
            throw new OccurrenceRefused(OccurrenceRefused::ARCHIVED);
        }
        $status = $this->firstOpen($project);
        $text = static fn (string $text): string => PeriodText::resolve($text, $occurrence);
        $now = $this->clock->now();
        $position = $this->tasks->nextPosition($project);
        try {
            $task = new Task($project, $text($recurring->title()), $status, $position, $recurring->owner(), $now, $recurring->section());
        } catch (\DomainException) {
            // The variables made the title too long.
            $task = new Task($project, mb_substr($text($recurring->title()), 0, Task::MAX_TITLE_LENGTH), $status, $position, $recurring->owner(), $now, $recurring->section());
        }
        $task->describe($text($recurring->description()));
        $task->dueOn($recurring->dueOn($occurrence));
        $task->prioritise($recurring->priority());
        $task->estimate($recurring->estimateMinutes());
        $task->makePrivate($recurring->isPrivate());
        $task->occurrenceOf($recurring, $occurrence);
        $this->tasks->add($task);
        try {
            if ([] !== $recurring->customFields()) {
                $this->fields->write(TaskExtension::RECORD, $task->id(), $this->decoded($recurring->customFields()));
            }
        } catch (\InvalidArgumentException|\DateMalformedStringException) {
            throw new OccurrenceRefused(OccurrenceRefused::CUSTOM_FIELDS);
        }
        foreach ($recurring->labelIds() as $labelId) {
            $label = $this->labels->label($labelId);
            if ($label instanceof Label && !$label->isDeleted()) {
                $this->tasks->add(new TaskLabel($task, $label));
            }
        }
        $this->people($recurring, $task, $now);
        $this->events->record(new TaskCreated($task->id(), $project->id(), null));
        foreach ($recurring->subtasks() as $index => $title) {
            try {
                $subtask = new Task($project, $text($title), $status, $position + 1 + $index, $recurring->owner(), $now, $recurring->section(), $task);
            } catch (\DomainException) {
                $subtask = new Task($project, mb_substr($text($title), 0, Task::MAX_TITLE_LENGTH), $status, $position + 1 + $index, $recurring->owner(), $now, $recurring->section(), $task);
            }
            $this->tasks->add($subtask);
            $this->events->record(new TaskCreated($subtask->id(), $project->id(), $task->id()));
        }

        return $task;
    }

    /**
     * The responsible and the watchers; whoever set it up follows too, as the
     * creator of a task does.
     */
    private function people(RecurringTask $recurring, Task $task, \DateTimeImmutable $now): void
    {
        $owner = $this->people->membershipOf($recurring->owner());
        $responsible = $recurring->responsible();
        $watchers = [];
        foreach ([$owner, $responsible, ...array_map($this->people->membership(...), $recurring->watcherIds())] as $membership) {
            if ($membership instanceof Membership && $membership->isActive()) {
                $watchers[$membership->id()] = $membership;
            }
        }
        if ($task->isPrivate()) {
            // Not saved yet, so seen by the share itself, as a task shared by hand is.
            foreach ($watchers as $membership) {
                $this->shares->add(WorkShare::of($task, $membership, $now));
            }
        } else {
            $watchers = array_filter($watchers, fn (Membership $membership): bool => $this->visibility->canMembershipSeeTask($membership, $task));
        }
        if ($responsible instanceof Membership && isset($watchers[$responsible->id()])) {
            $task->assign($responsible);
        }
        foreach ($watchers as $membership) {
            $this->tasks->add(new TaskWatcher($task, $membership));
        }
    }

    /**
     * The values as JSON keeps them, as Fields::write() takes them; a field no
     * longer there is left out.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function decoded(array $values): array
    {
        $decoded = [];
        foreach ($values as $key => $value) {
            $definition = $this->fields->definition(TaskExtension::RECORD, $key);
            if (!$definition instanceof FieldDefinition) {
                continue;
            }
            $decoded[$key] = match (true) {
                FieldKind::Date === $definition->kind && \is_string($value) => new \DateTimeImmutable($value.' 00:00:00'),
                FieldKind::DateTime === $definition->kind && \is_string($value) => new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone(date_default_timezone_get())),
                default => $value,
            };
        }

        return $decoded;
    }

    /**
     * @throws OccurrenceRefused
     */
    private function firstOpen(Project $project): ProjectStatus
    {
        $statuses = $this->projects->statuses($project);
        foreach ($statuses as $status) {
            if (StatusType::Open === $status->type()) {
                return $status;
            }
        }

        return $statuses[0] ?? throw new OccurrenceRefused(OccurrenceRefused::NO_STATUS);
    }
}
