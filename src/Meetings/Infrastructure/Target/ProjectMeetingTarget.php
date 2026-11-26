<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Target;

use Foresi\Meetings\Contract\MeetingTarget;
use Foresi\Work\Contract\ProjectDirectory;
use Foresi\Work\Contract\ProjectSummary;

/**
 * A meeting about a project of Work, which Meetings always has: the project is
 * seen by whoever sees it in Work, and an archived one takes no new meetings,
 * as it takes no new tasks.
 */
final readonly class ProjectMeetingTarget implements MeetingTarget
{
    public const string KIND = 'project';

    public function __construct(private ProjectDirectory $projects)
    {
    }

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function canSee(string $kind, string $id): bool
    {
        return $this->project($kind, $id) instanceof ProjectSummary;
    }

    public function canLink(string $kind, string $id): bool
    {
        $project = $this->project($kind, $id);

        return $project instanceof ProjectSummary && !$project->archived;
    }

    public function label(string $kind, string $id): ?string
    {
        return $this->project($kind, $id)?->name;
    }

    public function url(string $kind, string $id): ?string
    {
        return $this->project($kind, $id)?->url;
    }

    private function project(string $kind, string $id): ?ProjectSummary
    {
        return self::KIND === $kind ? $this->projects->find($id) : null;
    }
}
