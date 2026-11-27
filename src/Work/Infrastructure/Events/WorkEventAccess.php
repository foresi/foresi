<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Events;

use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Event\EventReadAccess;
use Foresi\Shared\Application\Event\EventSubjectAccess;
use Foresi\Shared\Contract\Event\EventSubject;
use Foresi\Work\Application\Projects;
use Foresi\Work\Application\RecurringTasks;
use Foresi\Work\Application\Tasks;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Task;

/**
 * The events of projects and tasks are read by the people of the organisation,
 * and each only by who sees its task as WorkVisibility decides: a private task
 * or a project not shared with a member stays out of their feed and their
 * webhooks, as it stays out of their lists. A task in the trash counts as it
 * would out of it. A project's events, such as a change of its budget, are
 * read by who sees the project, and so are those of a recurring task.
 */
final readonly class WorkEventAccess implements EventReadAccess, EventSubjectAccess
{
    public function __construct(private WorkVisibility $visibility, private ActorRole $role, private Tasks $tasks, private People $people, private Projects $projects, private RecurringTasks $recurring)
    {
    }

    public function module(): string
    {
        return 'work';
    }

    public function mayRead(): bool
    {
        return $this->visibility->canManage();
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->role->isStaffMember($userId);
    }

    public function sees(EventSubject $subject): bool
    {
        $project = $this->project($subject);
        if ($project instanceof Project) {
            return $this->visibility->canSeeProject($project);
        }
        $task = $this->task($subject);

        return $task instanceof Task && $this->visibility->canSeeTask($task);
    }

    public function userSees(string $userId, EventSubject $subject): bool
    {
        $membership = $this->people->membershipOf($userId);
        if (!$membership instanceof Membership || !$membership->isActive()) {
            return false;
        }
        $project = $this->project($subject);
        if ($project instanceof Project) {
            return $this->visibility->scopeOf($membership)->seesProject($project);
        }
        $task = $this->task($subject);

        return $task instanceof Task && $this->visibility->scopeOf($membership)->seesTask($task);
    }

    private function project(EventSubject $subject): ?Project
    {
        return match ($subject->type) {
            'project' => $this->projects->project($subject->id),
            'recurring_task' => $this->recurring->find($subject->id)?->project(),
            default => null,
        };
    }

    private function task(EventSubject $subject): ?Task
    {
        if ('task' !== $subject->type) {
            return null;
        }

        return $this->tasks->task($subject->id) ?? $this->tasks->trashed($subject->id);
    }
}
