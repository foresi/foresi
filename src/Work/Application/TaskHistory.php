<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Identity\Application\People;
use App\Identity\Application\UserRepository;
use App\Work\Domain\CommentMention;
use App\Work\Domain\Label;
use App\Work\Domain\Priority;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;
use App\Work\Domain\TaskLabel;
use App\Work\Domain\TaskWatcher;
use App\Work\Domain\WorkShare;

/**
 * The audit log of a task as steps a person recognises: one action is one
 * step, as the commands save it under one correlation. The status, the person
 * responsible, watchers and labels are told by name; the other fields by the
 * translation key of their label. A step that only moved the task among its
 * neighbours shows nothing and is left out. The due date and the priority
 * are told from what they were to what they became. A status or label removed since
 * keeps the last name the log knew. Making the task private or open again,
 * and sharing it with people or taking it back, are told with their names.
 *
 * Comments are told by the discussion of the task, not here: a step that
 * wrote, edited or deleted a comment is left out, the watcher it may have
 * added with it included.
 */
final readonly class TaskHistory
{
    /** The plain fields of a task, by the last part of their translation key. */
    private const array FIELDS = ['title' => 'title', 'description' => 'description', 'section' => 'section'];

    public function __construct(
        private VisibleWork $work,
        private AuditTrail $trail,
        private UserRepository $users,
        private People $people,
        private Projects $projects,
        private Labels $labels,
    ) {
    }

    /**
     * @return list<TaskHistoryEntry> oldest first
     *
     * @throws WorkRefused when the task is unknown or not visible
     */
    public function of(string $taskId): array
    {
        $task = $this->work->task($taskId);
        $entries = $this->trail->rootHistory(Task::class, $task->id());
        $users = $this->users->namesOf(array_values(array_unique(array_filter(array_map(static fn (AuditEntry $entry): ?string => $entry->userId(), $entries), \is_string(...)))));
        $people = [];
        foreach ($this->people->all() as $person) {
            $people[$person->membershipId] = $person->name;
        }
        $statuses = $this->statusNames($task->project());
        $labels = [];
        foreach ($this->labels->all() as $label) {
            $labels[$label->id()] = $label->name();
        }

        $steps = [];
        foreach (AuditSteps::of($entries) as $group) {
            $step = $this->step($group, $users, $people, $statuses, $labels);
            if ($step instanceof TaskHistoryEntry) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * @param non-empty-list<AuditEntry> $group
     * @param array<string, string>      $users    names by user id
     * @param array<string, string>      $people   names by membership id
     * @param array<string, string>      $statuses names by status id
     * @param array<string, string>      $labels   names by label id
     */
    private function step(array $group, array $users, array $people, array $statuses, array &$labels): ?TaskHistoryEntry
    {
        foreach ($group as $entry) {
            if (\in_array($entry->entityClass(), [TaskComment::class, CommentMention::class], true)) {
                return null;
            }
        }
        $userId = $group[0]->userId();
        $userName = null === $userId ? null : ($users[$userId] ?? null);
        $fields = [];
        $status = null;
        $responsible = null;
        $due = null;
        $priority = null;
        $watchers = [[], []];
        $tagged = [[], []];
        $shares = [[], []];
        $private = null;
        foreach ($group as $entry) {
            $changes = $entry->changes();
            $removed = AuditAction::Deleted === $entry->action();
            if (Task::class === $entry->entityClass()) {
                if (AuditAction::Created === $entry->action()) {
                    return new TaskHistoryEntry($group[0]->occurredAt(), $userName, TaskHistoryEntry::CREATED);
                }
                foreach (self::FIELDS as $field => $key) {
                    if (\array_key_exists($field, $changes)) {
                        $fields[] = 'work.task.field.'.$key;
                    }
                }
                $status = isset($changes['status']) ? [$this->id($changes['status'][0] ?? null), $this->id($changes['status'][1] ?? null)] : $status;
                $responsible = \array_key_exists('responsible', $changes) ? [$this->id($changes['responsible'][0] ?? null), $this->id($changes['responsible'][1] ?? null)] : $responsible;
                $due = \array_key_exists('dueOn', $changes) ? [$this->date($changes['dueOn'][0] ?? null), $this->date($changes['dueOn'][1] ?? null)] : $due;
                $priority = isset($changes['priority']) ? [$this->priority($changes['priority'][0] ?? null), $this->priority($changes['priority'][1] ?? null)] : $priority;
                $private = \array_key_exists('private', $changes) ? self::flag($changes['private'][1] ?? null) : $private;
            } elseif (WorkShare::class === $entry->entityClass()) {
                $id = $this->id($changes['membership'][$removed ? 0 : 1] ?? null);
                if (null !== $id) {
                    $shares[$removed ? 1 : 0][] = $people[$id] ?? '';
                }
            } elseif (TaskWatcher::class === $entry->entityClass()) {
                $id = $this->id($changes['membership'][$removed ? 0 : 1] ?? null);
                if (null !== $id) {
                    $watchers[$removed ? 1 : 0][] = $people[$id] ?? '';
                }
            } elseif (TaskLabel::class === $entry->entityClass()) {
                $id = $this->id($changes['label'][$removed ? 0 : 1] ?? null);
                if (null !== $id) {
                    $tagged[$removed ? 1 : 0][] = $labels[$id] ??= $this->labelName($id);
                }
            }
        }
        if ([] === $fields && null === $status && null === $responsible && null === $due && null === $priority && [[], []] === $watchers && [[], []] === $tagged && null === $private && [[], []] === $shares) {
            return null;
        }

        return new TaskHistoryEntry(
            $group[0]->occurredAt(),
            $userName,
            TaskHistoryEntry::CHANGED,
            array_values(array_unique($fields)),
            null !== $status,
            null === $status || null === $status[0] ? null : ($statuses[$status[0]] ?? null),
            null === $status || null === $status[1] ? null : ($statuses[$status[1]] ?? null),
            null !== $responsible,
            null === $responsible || null === $responsible[0] ? null : ($people[$responsible[0]] ?? null),
            null === $responsible || null === $responsible[1] ? null : ($people[$responsible[1]] ?? null),
            $watchers[0],
            $watchers[1],
            $tagged[0],
            $tagged[1],
            null !== $due,
            $due[0] ?? null,
            $due[1] ?? null,
            $priority[0] ?? null,
            $priority[1] ?? null,
            $private,
            $shares[0],
            $shares[1],
        );
    }

    /**
     * The current names of the statuses of the project, and the last names of those removed.
     *
     * @return array<string, string>
     */
    private function statusNames(Project $project): array
    {
        $names = [];
        foreach ($this->trail->rootHistory(Project::class, $project->id()) as $entry) {
            if (ProjectStatus::class === $entry->entityClass()) {
                $name = $entry->changes()['name'][1] ?? null;
                if (\is_string($name)) {
                    $names[$entry->entityId()] = $name;
                }
            }
        }
        foreach ($this->projects->statuses($project) as $status) {
            $names[$status->id()] = $status->name();
        }

        return $names;
    }

    /**
     * The last name of a label removed since, from its own log.
     */
    private function labelName(string $labelId): string
    {
        $name = '';
        foreach ($this->trail->rootHistory(Label::class, $labelId) as $entry) {
            $value = $entry->changes()['name'][AuditAction::Deleted === $entry->action() ? 0 : 1] ?? null;
            $name = \is_string($value) ? $value : $name;
        }

        return $name;
    }

    /**
     * A date as the log keeps it, the day alone.
     */
    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $day)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day[0]);

        return false === $date ? null : $date;
    }

    private function priority(mixed $value): Priority
    {
        return (\is_string($value) ? Priority::tryFrom($value) : null) ?? Priority::None;
    }

    /**
     * A flag as the log keeps it.
     */
    public static function flag(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value;
    }

    private function id(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
