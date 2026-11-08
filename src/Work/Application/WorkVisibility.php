<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Identity\Domain\Membership;
use App\Work\Domain\Project;
use App\Work\Domain\Task;

/**
 * The one place that decides what of the work of the organisation the
 * signed-in person may see. Every command loads, asks here, then acts; every
 * query applies the scope. What the person may not see is not found.
 */
interface WorkVisibility
{
    /**
     * Whether the person may start projects and keep the labels of the organisation.
     */
    public function canManage(): bool;

    public function canSeeProject(Project $project): bool;

    public function canSeeTask(Task $task): bool;

    /**
     * Whether another person of the organisation may see the task: whom a
     * comment can mention and notify.
     */
    public function canMembershipSeeTask(Membership $membership, Task $task): bool;

    public function scope(): WorkScope;
}
