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
        $direction = $descending ? -1 : 1;
        $at = array_flip(array_map(static fn (ProjectRow $row): string => $row->id, $rows));
        usort($rows, static function (ProjectRow $a, ProjectRow $b) use ($sort, $direction, $at): int {
            if (self::DUE === $sort && (!$a->nearestDue instanceof \DateTimeImmutable) !== (!$b->nearestDue instanceof \DateTimeImmutable)) {
                return $a->nearestDue instanceof \DateTimeImmutable ? -1 : 1;
            }
            $by = match ($sort) {
                self::OPEN => $a->openTasks <=> $b->openTasks,
                self::OVERDUE => $a->overdueTasks <=> $b->overdueTasks,
                self::UNASSIGNED => $a->unassignedTasks <=> $b->unassignedTasks,
                self::DUE => $a->nearestDue <=> $b->nearestDue,
                default => 0,
            };

            return 0 === $by ? $direction * ($at[$a->id] <=> $at[$b->id]) : $direction * $by;
        });

        return $rows;
    }
}
