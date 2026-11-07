<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Party\Domain\Party;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * The owner's invitation of a person into the organisation, by e-mail, as a
 * member or as an external person who represents a contact. Only the SHA-256 of
 * its link token is kept; the link works once and for a week.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invitation')]
#[ORM\Index(name: 'idx_invitation_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_invitation_email', columns: ['tenant_id', 'email'])]
#[ORM\UniqueConstraint(name: 'uniq_invitation_token', columns: ['token_hash'])]
class Invitation implements TenantOwned, Audited
{
    public const int VALID_DAYS = 7;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(name: 'display_name', type: 'string', length: 255)]
    private string $displayName;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'accepted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        string $email,
        string $displayName,
        #[ORM\Column(type: 'string', length: 32, enumType: Role::class)]
        private Role $role,
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: true)]
        private ?Party $party,
        #[ORM\Column(name: 'invited_by', type: 'guid')]
        private string $invitedBy,
        #[ORM\Column(type: 'string', length: 2)]
        private string $locale,
        #[AuditExcluded]
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
        private string $tokenHash,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $email = strtolower(trim($email));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('An invitation needs a valid e-mail address.');
        }
        $displayName = trim($displayName);
        if ('' === $displayName) {
            throw new \InvalidArgumentException('An invitation needs the name of the person.');
        }
        if (Role::Owner === $role) {
            throw new \InvalidArgumentException('An invitation is for a member or an external person.');
        }
        if (Role::External === $role && !$party instanceof Party) {
            throw new \InvalidArgumentException('An external person represents a contact.');
        }
        if (!\in_array($locale, User::LOCALES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported locale "%s".', $locale));
        }
        $this->assertHash($tokenHash);
        $this->id = Id::generate();
        $this->email = $email;
        $this->displayName = $displayName;
        $this->expiresAt = $createdAt->modify(sprintf('+%d days', self::VALID_DAYS));
    }

    public function state(\DateTimeImmutable $now): InvitationState
    {
        return match (true) {
            $this->acceptedAt instanceof \DateTimeImmutable => InvitationState::Accepted,
            $this->revokedAt instanceof \DateTimeImmutable => InvitationState::Revoked,
            $now >= $this->expiresAt => InvitationState::Expired,
            default => InvitationState::Pending,
        };
    }

    public function isPending(\DateTimeImmutable $now): bool
    {
        return InvitationState::Pending === $this->state($now);
    }

    /**
     * A new link for a week from now; the old one stops working.
     */
    public function renew(string $tokenHash, \DateTimeImmutable $now): void
    {
        if ($this->acceptedAt instanceof \DateTimeImmutable || $this->revokedAt instanceof \DateTimeImmutable) {
            throw new \DomainException('An accepted or revoked invitation is not sent again.');
        }
        $this->assertHash($tokenHash);
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $now->modify(sprintf('+%d days', self::VALID_DAYS));
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        if (!$this->acceptedAt instanceof \DateTimeImmutable && !$this->revokedAt instanceof \DateTimeImmutable) {
            $this->revokedAt = $now;
        }
    }

    public function accept(\DateTimeImmutable $now): void
    {
        if (!$this->isPending($now)) {
            throw new \DomainException('Only a pending invitation can be accepted.');
        }
        $this->acceptedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function role(): Role
    {
        return $this->role;
    }

    public function party(): ?Party
    {
        return $this->party;
    }

    public function invitedBy(): string
    {
        return $this->invitedBy;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function assertHash(string $hash): void
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new \InvalidArgumentException('An invitation keeps the SHA-256 of its token.');
        }
    }
}
