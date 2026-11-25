<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Activities;

use Foresi\Activities\Contract\ActivityTarget;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Infrastructure\Controller\ProjectController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Activities about a project, seen by whoever sees the project as its page
 * shows it: the organisation for a project of the organisation, the chosen
 * people for a private one, and a guest it is shared with. An archived project
 * keeps its activities and takes no new ones, as it takes no new tasks.
 */
final readonly class ProjectActivityTarget implements ActivityTarget
{
    public const string KIND = 'project';

    public function __construct(private WorkQueries $queries, private UrlGeneratorInterface $urls)
    {
    }

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function canSee(string $kind, string $id): bool
    {
        return $this->project($kind, $id) instanceof ProjectRow;
    }

    public function canLink(string $kind, string $id): bool
    {
        $project = $this->project($kind, $id);

        return $project instanceof ProjectRow && !$project->archived;
    }

    public function label(string $kind, string $id): ?string
    {
        return $this->project($kind, $id)?->name;
    }

    public function url(string $kind, string $id): ?string
    {
        $project = $this->project($kind, $id);

        return $project instanceof ProjectRow ? $this->urls->generate(ProjectController::PROJECT_ROUTE, ['id' => $project->id]) : null;
    }

    private function project(string $kind, string $id): ?ProjectRow
    {
        if (self::KIND !== $kind || !Id::isValid($id)) {
            return null;
        }

        return $this->queries->project($id)?->row;
    }
}
