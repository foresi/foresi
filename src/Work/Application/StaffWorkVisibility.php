<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Identity\Application\StaffCheck;
use App\Identity\Domain\Membership;
use App\Work\Domain\Project;
use App\Work\Domain\Task;

/**
 * The people who work for the organisation, its owners and members, see all its
 * projects and tasks; anyone else, a guest included, sees none of them yet.
 */
final readonly class StaffWorkVisibility implements WorkVisibility
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function canManage(): bool
    {
        return $this->staff->isStaff();
    }

    public function canSeeProject(Project $project): bool
    {
        return $this->staff->isStaff();
    }

    public function canSeeTask(Task $task): bool
    {
        return $this->canSeeProject($task->project());
    }

    public function canMembershipSeeTask(Membership $membership, Task $task): bool
    {
        return $membership->isActive() && $membership->role()->isStaff();
    }

    public function scope(): WorkScope
    {
        return $this->staff->isStaff() ? WorkScope::everything() : WorkScope::nothing();
    }
}
