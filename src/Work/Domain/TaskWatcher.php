<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Domain\Id;

/**
 * A person following a task without being responsible for it. A row of its
 * own rather than a collection on the task, so adding and removing a watcher
 * is audited under the task like any other change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_watcher')]
#[ORM\Index(name: 'idx_task_watcher_membership', columns: ['membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_task_watcher', columns: ['task_id', 'membership_id'])]
class TaskWatcher implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Task $task,
        #[ORM\ManyToOne(targetEntity: Membership::class)]
        #[ORM\JoinColumn(name: 'membership_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Membership $membership,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->task;
    }

    public function task(): Task
    {
        return $this->task;
    }

    public function membership(): Membership
    {
        return $this->membership;
    }
}
