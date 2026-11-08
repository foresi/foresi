<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

final readonly class ProjectList
{
    /**
     * @param list<SectionTasks> $sections the tasks in no section first, then the sections in order
     */
    public function __construct(public ProjectSummary $project, public array $sections)
    {
    }
}
