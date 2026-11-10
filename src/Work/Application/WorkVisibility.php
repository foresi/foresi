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

/**
 * The one place that decides what of the work of the organisation the
 * signed-in person may see and do. Every command loads, asks here, then acts;
 * every query applies the scope. What the person may not see is not found.
 * The rule itself is WorkScope's.
 */
interface WorkVisibility
{
    /**
     * Whether the person works for the organisation, as its owner or a team
     * member: starts projects, keeps their settings and the labels, shares.
     */
    public function canManage(): bool;

    /**
     * Whether the person sees the project, its page and list: the parts of it
     * they see, all of it when it is open to them.
     */
    public function canSeeProject(Project $project): bool;

    /**
     * Whether the person may know the name of the project, for orientation
     * around a task of it shared with them, though they do not see the project.
     */
    public function canSeeProjectName(Project $project): bool;

    public function canSeeSection(Section $section): bool;

    public function canSeeTask(Task $task): bool;

    /**
     * Whether the person may add a task to the project, in the section or in none.
     */
    public function canCreateTaskIn(Project $project, ?Section $section): bool;

    public function canComment(Task $task): bool;

    /**
     * Whether the person may log time on the task, or on the project without a
     * task: the people of the organisation where they see it, never guests.
     */
    public function canLogTime(Project $project, ?Task $task): bool;

    /**
     * Whether the person reads internal comments and their files: the people
     * of the organisation do, guests never.
     */
    public function canSeeInternal(): bool;

    /**
     * Whether the person may share the item, or change who sees it.
     */
    public function canShare(Project|Section|Task $item): bool;

    /**
     * Whether another person of the organisation may see the task: whom a
     * comment can mention and notify, and who can be responsible for it.
     */
    public function canMembershipSeeTask(Membership $membership, Task $task): bool;

    public function scope(): WorkScope;

    /**
     * The scope of another person of the organisation, for what is read for
     * them rather than by them: their inbox, their mail. Nothing for a person
     * whose membership is deactivated.
     */
    public function scopeOf(Membership $membership): WorkScope;
}
