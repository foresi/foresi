<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * A section of the list with its top-level tasks; the id and name are null for
 * the tasks in no section, which the list shows first.
 */
final readonly class SectionTasks
{
    /**
     * @param list<TaskRow> $tasks   by position
     * @param bool          $private whether only the people it is shared with see it
     */
    public function __construct(public ?string $id, public ?string $name, public array $tasks, public bool $private = false)
    {
    }
}
