<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Identity\Domain\Membership;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\WorkShare;

/**
 * The projects, sections and tasks shared with people.
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
