<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Integration\Work;

use Foresi\Deals\Application\LineProject;
use Foresi\Deals\Application\LineProjectSource;
use Foresi\Work\Contract\ProjectDirectory;
use Foresi\Work\Contract\ProjectSummary;

/**
 * The projects an hourly line links to, read from Work's contract as the person
 * signed in sees them.
 */
final readonly class WorkLineProjects implements LineProjectSource
{
    public function __construct(private ProjectDirectory $projects)
    {
    }

    public function find(string $projectId): ?LineProject
    {
        $project = $this->projects->find($projectId);

        return $project instanceof ProjectSummary ? $this->line($project) : null;
    }

    public function ofClient(string $clientId): array
    {
        $projects = [...$this->projects->ofClient($clientId), ...$this->projects->ofClient(null)];
        usort($projects, static fn (ProjectSummary $a, ProjectSummary $b): int => strnatcasecmp($a->name, $b->name));

        return array_map($this->line(...), $projects);
    }

    private function line(ProjectSummary $project): LineProject
    {
        return new LineProject($project->id, $project->name, $project->clientId, $project->archived, $project->url);
    }
}
