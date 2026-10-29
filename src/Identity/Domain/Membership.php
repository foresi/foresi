<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Audit\Domain\Audited;
use App\Party\Domain\Party;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links a user to a tenant with a role. For a client or subcontractor it can
 * point at the party that person represents.
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
#[ORM\Index(name: 'idx_membership_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_membership_tenant_user', columns: ['tenant_id', 'user_id'])]
class Membership implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false)]
        private readonly User $user,
        #[ORM\Column(type: 'string', length: 32, enumType: Role::class)]
        private readonly Role $role,
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: true)]
        private readonly ?Party $party = null,
    ) {
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function role(): Role
    {
        return $this->role;
    }

    public function party(): ?Party
    {
        return $this->party;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
