<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Identity\Domain\Membership;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A project, a section or a task shared with one person of the organisation:
 * exactly one of the three. A row of its own rather than a collection on the
 * item, so sharing and unsharing are audited like any other change: under the
 * task when a task is shared, under the project otherwise.
 *
 * A person has at most one share of an item; the database keeps it so with
 * one unique index per kind, as a null never collides with another.
 */
#[ORM\Entity]
#[ORM\Table(name: 'work_share')]
#[ORM\Index(name: 'idx_work_share_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_work_share_project', columns: ['project_id'])]
#[ORM\Index(name: 'idx_work_share_section', columns: ['section_id'])]
#[ORM\Index(name: 'idx_work_share_task', columns: ['task_id'])]
#[ORM\UniqueConstraint(name: 'uniq_work_share_project', columns: ['membership_id', 'project_id'])]
#[ORM\UniqueConstraint(name: 'uniq_work_share_section', columns: ['membership_id', 'section_id'])]
#[ORM\UniqueConstraint(name: 'uniq_work_share_task', columns: ['membership_id', 'task_id'])]
class WorkShare implements TenantOwned, Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Membership::class)]
        #[ORM\JoinColumn(name: 'membership_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Membership $membership,
        #[ORM\ManyToOne(targetEntity: Project::class)]
        #[ORM\JoinColumn(name: 'project_id', nullable: true, onDelete: 'RESTRICT')]
        private readonly ?Project $project,
        #[ORM\ManyToOne(targetEntity: Section::class)]
        #[ORM\JoinColumn(name: 'section_id', nullable: true, onDelete: 'RESTRICT')]
        private readonly ?Section $section,
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: true, onDelete: 'RESTRICT')]
        private readonly ?Task $task,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
    }

    public static function of(Project|Section|Task $item, Membership $membership, \DateTimeImmutable $now): self
    {
        return match (true) {
            $item instanceof Project => new self($membership, $item, null, null, $now),
            $item instanceof Section => new self($membership, null, $item, null, $now),
            $item instanceof Task => new self($membership, null, null, $item, $now),
        };
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->task ?? $this->projectOfItem();
    }

    public function membership(): Membership
    {
        return $this->membership;
    }

    /**
     * The project when the project itself is shared, null when a section or a task is.
     */
    public function project(): ?Project
    {
        return $this->project;
    }

    public function section(): ?Section
    {
        return $this->section;
    }

    public function task(): ?Task
    {
        return $this->task;
    }

    /**
     * The project the shared item is part of, or is.
     */
    public function projectOfItem(): Project
    {
        return $this->project
            ?? $this->section?->project()
            ?? $this->task?->project()
            ?? throw new \LogicException('A share is of a project, a section or a task.');
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
