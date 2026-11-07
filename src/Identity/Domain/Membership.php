<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Audit\Domain\Audited;
use App\Party\Domain\Party;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links a user to a tenant with a role. A guest membership always points at
 * the contact (party) the person represents, of whatever kind; a member may
 * point at their own person record. A deactivated membership keeps its history
 * but no longer lets the person into the organisation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
#[ORM\Index(name: 'idx_membership_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_membership_tenant_user', columns: ['tenant_id', 'user_id'])]
class Membership implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'disabled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false)]
        private readonly User $user,
        #[ORM\Column(type: 'string', length: 32, enumType: Role::class)]
        private Role $role,
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: true)]
        private ?Party $party = null,
    ) {
        $this->assertContact($role, $party);
        $this->id = Id::generate();
    }

    /**
     * A new role, with the contact the person represents in it.
     */
    public function changeRole(Role $role, ?Party $party): void
    {
        $this->assertContact($role, $party);
        $this->role = $role;
        $this->party = $party;
    }

    public function linkContact(?Party $party): void
    {
        $this->assertContact($this->role, $party);
        $this->party = $party;
    }

    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->disabledAt ??= $now;
    }

    public function reactivate(): void
    {
        $this->disabledAt = null;
    }

    public function isActive(): bool
    {
        return !$this->disabledAt instanceof \DateTimeImmutable;
    }

    public function id(): string
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

    private function assertContact(Role $role, ?Party $party): void
    {
        if (Role::Guest === $role && !$party instanceof Party) {
            throw new \InvalidArgumentException('A guest membership must link the contact the person represents.');
        }
    }
}
