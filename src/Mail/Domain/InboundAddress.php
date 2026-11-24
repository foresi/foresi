<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditExcluded;
use Foresi\Shared\Domain\Id;

/**
 * One inbound address: where mail for one purpose is sent, such as the payment
 * notices of a bank account. The secret token in the address is never stored as
 * it is: its SHA-256 hash is the key mail is routed by, and a sealed copy, kept
 * only for a purpose that shows its addresses again, opens it for the owner.
 * Neither goes to the audit log, nor does the time of the last message. Rotating
 * replaces both at once, so the old address stops being found the moment the new
 * one is stored; a revoked address takes in nothing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mail_inbound_address')]
#[ORM\UniqueConstraint(name: 'uniq_mail_inbound_address_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_mail_inbound_address_owner', columns: ['owner_user_id'])]
class InboundAddress implements Audited
{
    public const int MAX_PURPOSE = 40;
    public const int MAX_LABEL = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_PURPOSE)]
    private string $purpose;

    #[ORM\Column(type: 'string', length: self::MAX_LABEL)]
    private string $label;

    #[AuditExcluded]
    #[ORM\Column(name: 'token_hash', type: 'string', length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[AuditExcluded]
    #[ORM\Column(name: 'token_sealed', type: 'text', nullable: true)]
    private ?string $tokenSealed;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** Written by plain SQL as mail arrives; see InboundAddressStore::touch(). */
    #[AuditExcluded]
    #[ORM\Column(name: 'last_received_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastReceivedAt = null;

    /**
     * @param string      $tokenHash   the lowercase hex SHA-256 of the token
     * @param string|null $tokenSealed the token, sealed, for a purpose that shows its addresses again
     */
    public function __construct(
        string $purpose,
        #[ORM\Column(name: 'owner_user_id', type: 'guid', nullable: true)]
        private ?string $ownerUserId,
        string $label,
        string $tokenHash,
        ?string $tokenSealed,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $purpose = trim($purpose);
        if ('' === $purpose || \strlen($purpose) > self::MAX_PURPOSE) {
            throw new \DomainException('A purpose key is 1 to 40 characters.');
        }
        if (null !== $ownerUserId && '' === trim($ownerUserId)) {
            throw new \DomainException('An owner is named by an id.');
        }
        $this->id = Id::generate();
        $this->purpose = $purpose;
        $this->label = mb_substr(trim($label), 0, self::MAX_LABEL);
        $this->tokenHash = $this->checkedHash($tokenHash);
        $this->tokenSealed = $this->checkedSealed($tokenSealed);
    }

    /**
     * A new token in place of the old one, which is not found any more.
     */
    public function rotate(string $tokenHash, ?string $tokenSealed): void
    {
        $tokenHash = $this->checkedHash($tokenHash);
        if (hash_equals($this->tokenHash, $tokenHash)) {
            throw new \DomainException('A new token must differ from the old one.');
        }
        $this->tokenHash = $tokenHash;
        $this->tokenSealed = $this->checkedSealed($tokenSealed);
        $this->lastReceivedAt = null;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function ownerUserId(): ?string
    {
        return $this->ownerUserId;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function tokenSealed(): ?string
    {
        return $this->tokenSealed;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof \DateTimeImmutable;
    }

    public function lastReceivedAt(): ?\DateTimeImmutable
    {
        return $this->lastReceivedAt;
    }

    private function checkedHash(string $hash): string
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $hash)) {
            throw new \DomainException('A token hash is 64 lowercase hex digits.');
        }

        return $hash;
    }

    private function checkedSealed(?string $sealed): ?string
    {
        if ('' === $sealed) {
            throw new \DomainException('The sealed token is missing.');
        }

        return $sealed;
    }
}
