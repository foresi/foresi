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
}
