<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Domain\Id;

/**
 * A task a person put on their own shortlist, My priorities (plan 37.2), at its place in
 * the order they gave it: Freelo's "My priorities", which only the person sees and which
 * keeps a task whoever becomes responsible for it and whenever it falls due.
 *
 * It is the person's own, so it is not audited under the task: the task's history, which
 * others read, never says who starred it. A finished task leaves the list (ManageTasks).
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_star')]
#[ORM\Index(name: 'idx_task_star_membership', columns: ['membership_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_task_star', columns: ['task_id', 'membership_id'])]
class TaskStar
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Task $task,
        #[ORM\ManyToOne(targetEntity: Membership::class)]
        #[ORM\JoinColumn(name: 'membership_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Membership $membership,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function task(): Task
    {
        return $this->task;
    }

    public function membership(): Membership
    {
        return $this->membership;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
