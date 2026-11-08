<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A label on a task. A row of its own rather than a collection on the task,
 * so labelling is audited under the task like any other change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_label')]
#[ORM\Index(name: 'idx_task_label_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_task_label_label', columns: ['label_id'])]
#[ORM\UniqueConstraint(name: 'uniq_task_label', columns: ['task_id', 'label_id'])]
class TaskLabel implements TenantOwned, Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Task $task,
        #[ORM\ManyToOne(targetEntity: Label::class)]
        #[ORM\JoinColumn(name: 'label_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Label $label,
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

    public function label(): Label
    {
        return $this->label;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
