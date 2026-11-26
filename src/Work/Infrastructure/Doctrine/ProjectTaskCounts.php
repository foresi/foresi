<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\RowConditions;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Infrastructure\View\DqlConditions;
use Foresi\Work\Application\View\ProjectLists;
use Foresi\Work\Application\WorkScope;
use Foresi\Work\Domain\Task;

/**
 * The task counts of a project the person sees, as the projects index shows them and a
 * view of it filters on: the unfinished tasks, those of them past their due date and
 * those with no one responsible, and the nearest due date of them. Subtasks count as
 * the list of all tasks lists them; a task in the trash counts for nothing.
 *
 * One query groups the tasks by project, so a page of projects costs one query whatever
 * its length. A filter on a count is the HAVING of that grouping as a subselect: a project
 * with no task has no group, so the conditions are checked at its counts (0, 0, 0, no
 * date) once, and such a project is in when they hold there (NOT IN the groups that fail)
 * or out when they do not (IN the groups that pass).
 */
final readonly class ProjectTaskCounts
{
    /** The fields of the projects index read from the counts, not from a project's columns. */
    public const array FIELDS = [ProjectLists::OPEN_TASKS, ProjectLists::OVERDUE_TASKS, ProjectLists::UNASSIGNED_TASKS, ProjectLists::NEXT_DUE];

    private const string UNFINISHED = 'pts.type IN (:pt_open)';

    public function __construct(private ListDefinition $list, private WorkScope $scope, private \DateTimeImmutable $today)
    {
    }

    /**
     * The filter in two: the conditions on the project's own fields, and those on its counts.
     *
     * @return array{ViewFilter, ViewFilter}
     */
    public static function split(ViewFilter $filter): array
    {
        $counts = static fn (Condition $condition): bool => \in_array($condition->field, self::FIELDS, true);

        return [
            new ViewFilter(array_values(array_filter($filter->conditions, static fn (Condition $condition): bool => !$counts($condition)))),
            new ViewFilter(array_values(array_filter($filter->conditions, $counts))),
        ];
    }

    /**
     * The condition on the project alias p that keeps the projects whose counts meet the
     * filter, with its parameters; "1 = 1" when the filter holds no count condition.
     *
     * @return array{string, array<string, array{mixed, string|ArrayParameterType|null}>}
     */
    public function where(ViewFilter $counts): array
    {
        if ([] === $counts->conditions) {
            return ['1 = 1', []];
        }
        $columns = array_combine(self::FIELDS, array_map($this->expression(...), self::FIELDS));
        $never = static fn (Condition $condition): never => throw new \LogicException(\sprintf('The count "%s" has its expression.', $condition->field));
        [$having, $parameters] = new DqlConditions($this->list, $columns, $never(...))->compile($counts, fn (): \DateTimeImmutable => $this->today, 'ptc');
        $none = [] !== RowConditions::filter([[0, null]], $counts, $this->list, static fn (array $zero, string $field): mixed => ProjectLists::NEXT_DUE === $field ? $zero[1] : $zero[0], $this->today);
        $seen = ScopedDql::tasks($this->scope, 'pt');
        $grouped = 'SELECT IDENTITY(pt.project) FROM '.Task::class.' pt JOIN pt.status pts'.$seen->joins.' WHERE pt.deletedAt IS NULL'.$seen->where.' GROUP BY pt.project HAVING ';

        return [
            $none ? 'p.id NOT IN ('.$grouped.'NOT ('.$having.'))' : 'p.id IN ('.$grouped.$having.')',
            $parameters + $this->parameters(),
        ];
    }

    /**
     * The DQL that reads the counts of the projects, one row per project with a task; its
     * parameters are those of parameters(), :pt_projects the ids of the projects.
     *
     * @return string selects project, open, overdue, unassigned and due
     */
    public function select(): string
    {
        $seen = ScopedDql::tasks($this->scope, 'pt');

        return 'SELECT IDENTITY(pt.project) AS project, '
            .$this->expression(ProjectLists::OPEN_TASKS).' AS open, '
            .$this->expression(ProjectLists::OVERDUE_TASKS).' AS overdue, '
            .$this->expression(ProjectLists::UNASSIGNED_TASKS).' AS unassigned, '
            .$this->expression(ProjectLists::NEXT_DUE).' AS due'
            .' FROM '.Task::class.' pt JOIN pt.status pts'.$seen->joins
            .' WHERE pt.project IN (:pt_projects) AND pt.deletedAt IS NULL'.$seen->where.' GROUP BY pt.project';
    }

    /**
     * The parameters of select() but the ids of the projects, the scope's among them.
     *
     * @return array<string, array{mixed, string|ArrayParameterType|null}>
     */
    public function parameters(): array
    {
        return ScopedDql::tasks($this->scope, 'pt')->parameters() + [
            'pt_open' => [TaskListDql::unfinished(), ArrayParameterType::STRING],
            'pt_today' => [$this->today, Types::DATE_IMMUTABLE],
        ];
    }

    /**
     * The aggregate of one count over the tasks of a project.
     */
    private function expression(string $field): string
    {
        return match ($field) {
            ProjectLists::OPEN_TASKS => 'SUM(CASE WHEN '.self::UNFINISHED.' THEN 1 ELSE 0 END)',
            ProjectLists::OVERDUE_TASKS => 'SUM(CASE WHEN '.self::UNFINISHED.' AND pt.dueOn < :pt_today THEN 1 ELSE 0 END)',
            ProjectLists::UNASSIGNED_TASKS => 'SUM(CASE WHEN '.self::UNFINISHED.' AND pt.responsible IS NULL THEN 1 ELSE 0 END)',
            // A finished task has no due date that matters: NULLIF leaves its date out of the MIN.
            ProjectLists::NEXT_DUE => 'MIN(CASE WHEN '.self::UNFINISHED.' THEN pt.dueOn ELSE NULLIF(pt.dueOn, pt.dueOn) END)',
            default => throw new \LogicException(\sprintf('The field "%s" is no count.', $field)),
        };
    }
}
