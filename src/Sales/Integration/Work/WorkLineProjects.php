<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Work;

use Foresi\Sales\Application\LineProject;
use Foresi\Sales\Application\LineProjectSource;
use Foresi\Work\Contract\ProjectDirectory;
use Foresi\Work\Contract\ProjectSummary;
use Foresi\Work\Contract\TimeTotals;

/**
 * The projects an hourly line links to, read from Work's contract as the person
 * signed in sees them.
 */
final readonly class WorkLineProjects implements LineProjectSource
{
    public function __construct(private ProjectDirectory $projects, private TimeTotals $time)
    {
    }

    public function find(string $projectId): ?LineProject
    {
        $project = $this->projects->find($projectId);

        return $project instanceof ProjectSummary ? $this->line($project) : null;
    }

    public function ofClient(string $clientId): array
    {
        return array_map($this->line(...), $this->projects->ofClient($clientId));
    }

    public function billableMinutes(string $projectId): int
    {
        return $this->time->minutes($projectId, billableOnly: true);
    }

    private function line(ProjectSummary $project): LineProject
    {
        return new LineProject($project->id, $project->name, $project->clientId, $project->archived, $project->url);
    }
}
