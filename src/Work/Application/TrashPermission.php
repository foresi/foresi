<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Work\Domain\Task;

/**
 * Who puts a task in the trash and takes it out again: the person who created
 * it, or an owner of the organisation, as with a comment. Anyone else who sees
 * the task may change it, but not make it disappear for the team.
 */
final readonly class TrashPermission
{
    public function __construct(private AuditActor $actor, private OwnerCheck $owner)
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
        if (null === $userId) {
            return static fn (string $createdBy): bool => false;
        }
        if ($this->owner->isOwner()) {
            return static fn (string $createdBy): bool => true;
        }

        return static fn (string $createdBy): bool => $createdBy === $userId;
    }
}
