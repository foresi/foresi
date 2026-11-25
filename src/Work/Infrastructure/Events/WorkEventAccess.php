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
use Foresi\Work\Application\Tasks;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Domain\Task;

/**
 * The events of projects and tasks are read by the people of the organisation,
 * and each only by who sees its task as WorkVisibility decides: a private task
 * or a project not shared with a member stays out of their feed and their
 * webhooks, as it stays out of their lists. A task in the trash counts as it
 * would out of it.
 */
final readonly class WorkEventAccess implements EventReadAccess, EventSubjectAccess
{
    public function __construct(private WorkVisibility $visibility, private ActorRole $role, private Tasks $tasks, private People $people)
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
        $task = $this->task($subject);

        return $task instanceof Task && $this->visibility->canSeeTask($task);
    }

    public function userSees(string $userId, EventSubject $subject): bool
    {
        $task = $this->task($subject);
        $membership = $this->people->membershipOf($userId);

        return $task instanceof Task && $membership instanceof Membership && $membership->isActive() && $this->visibility->scopeOf($membership)->seesTask($task);
    }

    private function task(EventSubject $subject): ?Task
    {
        if ('task' !== $subject->type) {
            return null;
        }

        return $this->tasks->task($subject->id) ?? $this->tasks->trashed($subject->id);
    }
}
