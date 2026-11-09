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
 * A group of tasks in the list of a project, independent of their status: a
 * phase, a deliverable, a part of the work. Audited under the project.
 *
 * A private section is hidden, with its tasks, from everyone it is not shared
 * with, even from those who see the project.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_section')]
#[ORM\Index(name: 'idx_project_section_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_project_section_position', columns: ['project_id', 'position'])]
class Section implements TenantOwned, Audited, AuditRooted
{
    public const int MAX_NAME_LENGTH = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $private = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Project::class)]
        #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Project $project,
        string $name,
        #[ORM\Column(type: 'integer')]
        private int $position,
    ) {
        $this->id = Id::generate();
        $this->rename($name);
    }

    public function rename(string $name): void
    {
        $this->name = Name::of($name, self::MAX_NAME_LENGTH, 'A section');
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function makePrivate(bool $private): void
    {
        $this->private = $private;
    }

    public function isPrivate(): bool
    {
        return $this->private;
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

    public function position(): int
    {
        return $this->position;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
