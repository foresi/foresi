<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * What the settings of a project change: the project itself, its sections and its statuses.
 */
final readonly class ProjectSettings
{
    /**
     * @param list<Choice>       $sections        by position
     * @param list<StatusChoice> $statuses        by position
     * @param list<string>       $privateSections the ids of the sections only the people they are shared with see
     */
    public function __construct(public ProjectSummary $project, public array $sections, public array $statuses, public array $privateSections = [])
    {
    }
}
