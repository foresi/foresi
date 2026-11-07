<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * A group of my tasks, by when they are due in the tenant's time zone.
 */
final readonly class TaskGroup
{
    public const string OVERDUE = 'overdue';
    public const string TODAY = 'today';
    /** The seven days after today. */
    public const string UPCOMING = 'upcoming';
    public const string LATER = 'later';
    public const string NO_DATE = 'no_date';

    /**
     * @param string        $key   one of the constants
     * @param list<TaskRow> $tasks by due date, then priority, highest first
     */
    public function __construct(public string $key, public array $tasks)
    {
    }

    /**
     * Every group in order, each even when empty, with the tasks in the order they come.
     *
     * @param list<TaskRow>      $tasks
     * @param \DateTimeImmutable $today midnight of today in the tenant's time zone
     *
     * @return list<self>
     */
    public static function split(array $tasks, \DateTimeImmutable $today): array
    {
        $groups = array_fill_keys([self::OVERDUE, self::TODAY, self::UPCOMING, self::LATER, self::NO_DATE], []);
        $day = $today->format('Y-m-d');
        $week = $today->modify('+7 days')->format('Y-m-d');
        foreach ($tasks as $task) {
            $due = $task->dueOn?->format('Y-m-d');
            $groups[match (true) {
                null === $due => self::NO_DATE,
                $due < $day => self::OVERDUE,
                $due === $day => self::TODAY,
                $due <= $week => self::UPCOMING,
                default => self::LATER,
            }][] = $task;
        }

        return array_map(static fn (string $key, array $rows): self => new self($key, $rows), array_keys($groups), array_values($groups));
    }
}
