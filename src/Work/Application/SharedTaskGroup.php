<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * The tasks shared with the person on their own in one project, which they
 * know by its name only.
 */
final readonly class SharedTaskGroup
{
    /**
     * @param list<TaskRow> $tasks by title
     */
    public function __construct(public string $projectName, public array $tasks)
    {
    }
}
