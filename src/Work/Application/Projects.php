<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Section;

/**
 * The projects their statuses and sections.
 */
interface Projects
{
    public function project(string $id): ?Project;

    public function status(string $id): ?ProjectStatus;

    public function section(string $id): ?Section;

    /**
     * @return list<ProjectStatus> by position
     */
    public function statuses(Project $project): array;

    /**
     * @return list<Section> by position
     */
    public function sections(Project $project): array;

    public function add(Project|ProjectStatus|Section $item): void;

    public function remove(ProjectStatus|Section $item): void;

    public function flush(): void;

    /**
     * Runs the work and saves what it changed in one database transaction:
     * all of it, or nothing.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed;
}
