<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * What the organisation shares with the signed-in person, for the start page
 * of a guest: the projects they see, then the tasks shared with them on their
 * own, by the name of their project.
 */
final readonly class SharedWork
{
    /**
     * @param list<SharedProject>   $projects by name
     * @param list<SharedTaskGroup> $tasks    by the name of the project
     */
    public function __construct(public array $projects, public array $tasks)
    {
    }

    public function isEmpty(): bool
    {
        return [] === $this->projects && [] === $this->tasks;
    }
}
