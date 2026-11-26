<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * The orders of the projects index: by name, by one of the task counts or by the next
 * due date. Equal rows follow their names the same way; a project with no due date is
 * last either way, as the list of all tasks keeps its undated tasks last.
 */
final readonly class ProjectOrder
{
    public const string NAME = 'name';
    public const string OPEN = 'open';
    public const string OVERDUE = 'overdue';
    public const string UNASSIGNED = 'unassigned';
    public const string DUE = 'due';
    public const array SORTS = [self::NAME, self::OPEN, self::OVERDUE, self::UNASSIGNED, self::DUE];

    /**
     * @param list<ProjectRow> $rows by name
     *
     * @return list<ProjectRow>
     */
    public static function sorted(array $rows, string $sort, bool $descending): array
    {
        $at = array_flip(array_map(static fn (ProjectRow $row): string => $row->id, $rows));
        usort($rows, static function (ProjectRow $a, ProjectRow $b) use ($sort, $descending, $at): int {
            if (self::DUE === $sort && (!$a->nearestDue instanceof \DateTimeImmutable) !== (!$b->nearestDue instanceof \DateTimeImmutable)) {
                return ($b->nearestDue instanceof \DateTimeImmutable) <=> ($a->nearestDue instanceof \DateTimeImmutable);
            }
            [$first, $second] = $descending ? [$b, $a] : [$a, $b];

            return [self::key($first, $sort), $at[$first->id]] <=> [self::key($second, $sort), $at[$second->id]];
        });

        return $rows;
    }

    /**
     * What a row is ordered by; null when the order is the name alone.
     */
    private static function key(ProjectRow $row, string $sort): int|\DateTimeImmutable|null
    {
        return match ($sort) {
            self::OPEN => $row->openTasks,
            self::OVERDUE => $row->overdueTasks,
            self::UNASSIGNED => $row->unassignedTasks,
            self::DUE => $row->nearestDue,
            default => null,
        };
    }
}
