<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;

/**
 * A task agreed at a meeting: the link between the meeting and the task made
 * from its minutes, made in the same transaction as the task. It is the task's
 * origin and is not read from the text, so taking the token out of the minutes
 * keeps the task and the link (spec, Action item; Loop keeps the task when its
 * block is removed). Meetings holds it, not Work. The task is an id of Work's,
 * without a foreign key, so the module installs and goes on its own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meetings_action_item')]
#[ORM\Index(name: 'idx_meetings_action_item_meeting', columns: ['meeting_id'])]
#[ORM\Index(name: 'idx_meetings_action_item_task', columns: ['task_id'])]
class ActionItem implements Audited, AuditRooted
{
    public function __construct(
        /** The id the editor chose for it, so that a second click on Create makes no second task. */
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\ManyToOne(targetEntity: Meeting::class)]
        #[ORM\JoinColumn(name: 'meeting_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Meeting $meeting,
        #[ORM\Column(name: 'task_id', type: 'guid')]
        private readonly string $taskId,
        #[ORM\Column(name: 'created_by', type: 'guid')]
        private readonly string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->meeting;
    }

    public function meeting(): Meeting
    {
        return $this->meeting;
    }

    public function taskId(): string
    {
        return $this->taskId;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
