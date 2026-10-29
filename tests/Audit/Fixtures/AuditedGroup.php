<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Audit\Fixtures;

use App\Audit\Domain\Audited;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test-only audited entity that owns a to-many association.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_audited_group')]
#[ORM\Index(name: 'idx_test_audited_group_tenant', columns: ['tenant_id'])]
class AuditedGroup implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    /** @var Collection<int, AuditedSecret> */
    #[ORM\ManyToMany(targetEntity: AuditedSecret::class)]
    #[ORM\JoinTable(name: 'test_audited_group_secret')]
    private Collection $members;

    public function __construct()
    {
        $this->members = new ArrayCollection();
    }

    public function add(AuditedSecret $secret): void
    {
        $this->members->add($secret);
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
