<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\View;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Work\Application\DueBucket;
use Foresi\Work\Application\TaskRow;

/**
 * Whether a top-level task of a project's list holds the conditions of a view
 * (ProjectTaskLists). The list reads a project's tasks whole, so the conditions
 * are checked on its rows, as the filters they replace were.
 *
 * A task is finished when its status is; a finished task is left out unless a
 * condition asks about the status. A due date falls in every bucket it matches:
 * today is also in the next seven days, and overdue is past and not finished. The
 * title and the priority are fields of the list's data source, matched on the row as
 * the query would: a text ignores case.
 */
final readonly class TaskConditions
{
    /**
     * @param ViewFilter         $filter the view's conditions, all of which hold
     * @param string|null        $me     the membership of the person looking; null matches no task as theirs
     * @param \DateTimeImmutable $today  today in the instance's time zone
     */
    public function __construct(private ViewFilter $filter, private ?string $me, private \DateTimeImmutable $today)
    {
    }

    public function matches(TaskRow $task): bool
    {
        if ([] === $this->filter->on(ProjectTaskLists::STATUS) && $task->done) {
            return false;
        }
        foreach ($this->filter->conditions as $condition) {
            $holds = match ($condition->field) {
                ProjectTaskLists::RESPONSIBLE => $this->responsible($condition, $task->responsibleId),
                ProjectTaskLists::STATUS => $this->any($condition, [$task->done ? ProjectTaskLists::FINISHED : ProjectTaskLists::OPEN]),
                ProjectTaskLists::DUE => $this->any($condition, $this->buckets($task)),
                ProjectTaskLists::TITLE => $this->text($condition, $task->title),
                ProjectTaskLists::PRIORITY => $this->any($condition, [$task->priority->value]),
                default => true,
            };
            if (!$holds) {
                return false;
            }
        }

        return true;
    }

    private function responsible(Condition $condition, ?string $responsibleId): bool
    {
        $ids = array_map($this->person(...), \is_array($condition->value) ? $condition->value : [$condition->value]);

        return match ($condition->op) {
            'is', 'is_any_of' => null !== $responsibleId && \in_array($responsibleId, $ids, true),
            'is_not' => null === $responsibleId || !\in_array($responsibleId, $ids, true),
            'is_empty' => null === $responsibleId,
            default => null !== $responsibleId,
        };
    }

    /**
     * Whether a text condition holds for a value, ignoring case.
     */
    private function text(Condition $condition, string $value): bool
    {
        // A title is trimmed when it is set (Name::of).
        $value = mb_strtolower($value);
        $wanted = mb_strtolower(\is_string($condition->value) ? trim($condition->value) : '');

        return match ($condition->op) {
            'contains' => str_contains($value, $wanted),
            'does_not_contain' => !str_contains($value, $wanted),
            'is' => $value === $wanted,
            'is_not' => $value !== $wanted,
            'is_empty' => '' === $value,
            default => '' !== $value,
        };
    }

    /**
     * The membership a value names: "me" is the person looking, who may be no one.
     */
    private function person(mixed $value): ?string
    {
        if (ProjectTaskLists::ME === $value) {
            return $this->me;
        }

        return \is_string($value) ? $value : null;
    }

    /**
     * Whether an enum condition holds for the values a task has of its field.
     *
     * @param list<string> $values
     */
    private function any(Condition $condition, array $values): bool
    {
        $wanted = \is_array($condition->value) ? $condition->value : [$condition->value];
        $hit = [] !== array_filter($wanted, static fn (mixed $value): bool => \in_array($value, $values, true));

        return \in_array($condition->op, ['is', 'is_any_of'], true) ? $hit : !$hit;
    }

    /**
     * @return list<string> the due buckets the task is in
     */
    private function buckets(TaskRow $task): array
    {
        if (!$task->dueOn instanceof \DateTimeImmutable) {
            return [DueBucket::None->value];
        }
        $due = $task->dueOn->format('Y-m-d');
        $today = $this->today->format('Y-m-d');
        $buckets = [];
        if ($due < $today && !$task->done) {
            $buckets[] = DueBucket::Overdue->value;
        }
        if ($due === $today) {
            $buckets[] = DueBucket::Today->value;
        }
        if ($due >= $today && $due <= $this->today->modify('+7 days')->format('Y-m-d')) {
            $buckets[] = DueBucket::NextSevenDays->value;
        }

        return $buckets;
    }
}
