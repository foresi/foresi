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
 * A status of the workflow of one project, a column on its board. The name is
 * the team's own; the type says what the status means. Audited under the project.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_status')]
#[ORM\Index(name: 'idx_project_status_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_project_status_position', columns: ['project_id', 'position'])]
class ProjectStatus implements TenantOwned, Audited, AuditRooted
{
    public const int MAX_NAME_LENGTH = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: self::MAX_NAME_LENGTH)]
    private string $name;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Project::class)]
        #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Project $project,
        string $name,
        #[ORM\Column(type: 'string', length: 16, enumType: StatusType::class)]
        private StatusType $type,
        #[ORM\Column(type: 'integer')]
        private int $position,
    ) {
        $this->id = Id::generate();
        $this->rename($name);
    }

    public function rename(string $name): void
    {
        $this->name = Name::of($name, self::MAX_NAME_LENGTH, 'A status');
    }

    /**
     * The tasks in the status keep their completion date; it changes when a task moves.
     */
    public function retype(StatusType $type): void
    {
        $this->type = $type;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->project;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): StatusType
    {
        return $this->type;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
