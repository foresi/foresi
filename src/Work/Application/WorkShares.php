<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Identity\Domain\Membership;
use App\Work\Domain\Project;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\WorkShare;

/**
 * The projects, sections and tasks of the active tenant shared with its people.
 */
interface WorkShares
{
    /**
     * Everything shared with the person, with the shared items loaded.
     *
     * @return list<WorkShare>
     */
    public function of(Membership $membership): array;

    /**
     * The people the item is shared with.
     *
     * @return list<WorkShare>
     */
    public function on(Project|Section|Task $item): array;

    public function find(Project|Section|Task $item, Membership $membership): ?WorkShare;

    public function add(WorkShare $share): void;

    /**
     * Removes for good; the log keeps that it was.
     */
    public function remove(WorkShare $share): void;
}
