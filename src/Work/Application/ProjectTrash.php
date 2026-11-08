<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

final readonly class ProjectTrash
{
    /**
     * @param list<TrashedTask> $tasks the last deleted first
     */
    public function __construct(public ProjectSummary $project, public array $tasks)
    {
    }
}
