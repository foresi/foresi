<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Work\Domain\Task;

/**
 * Who puts a task in the trash and takes it out again: the person who created
 * it, or an owner of the organisation, as with a comment. Anyone else who sees
 * the task may change it, but not make it disappear for the team. A guest
 * never does, not even with a task they created.
 */
final readonly class TrashPermission
{
    public function __construct(private AuditActor $actor, private OwnerCheck $owner, private StaffCheck $staff)
    {
    }

    public function allows(Task $task): bool
    {
        return $this->rule()($task->createdBy());
    }

    /**
     * The rule for the signed-in person, asked once for many tasks.
     *
     * @return \Closure(string): bool given the user id of who created a task
     */
    public function rule(): \Closure
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            return static fn (string $createdBy): bool => false;
        }
        if ($this->owner->isOwner()) {
            return static fn (string $createdBy): bool => true;
        }

        return static fn (string $createdBy): bool => $createdBy === $userId;
    }
}
