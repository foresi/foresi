<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

final readonly class ProjectBoard
{
    /**
     * @param list<StatusTasks> $columns the statuses in order
     */
    public function __construct(public ProjectSummary $project, public array $columns)
    {
    }
}
