<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Audit\Domain\AuditRooted;
use App\Party\Domain\FirmBankAccount;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * The inbound address of one bank account: where the bank's payment
 * notifications are sent. The secret token in the address is stored twice and
 * never as it is: its SHA-256 hash, the key mail is routed by, and a sealed copy
 * that only shows it again to the firm. Neither the sealed copy nor the time of
 * the last message goes to the audit log; a new token shows there as a new
 * hash and generation. Rotating replaces both at once, so the old address stops
 * being found the moment the new one is stored.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bank_feed')]
#[ORM\Index(name: 'idx_bank_feed_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_bank_feed_account', columns: ['firm_bank_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_bank_feed_token', columns: ['token_hash'])]
class BankFeed implements TenantOwned, Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'token_hash', type: 'string', length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[AuditExcluded]
    #[ORM\Column(name: 'token_sealed', type: 'text')]
    private string $tokenSealed;

    /** 1 for the first token, one more for every new one. */
    #[ORM\Column(type: 'integer')]
    private int $generation = 1;

    #[ORM\Column(name: 'rotated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    /** Written by plain SQL as mail arrives; see BankFeeds::touch(). */
    #[AuditExcluded]
    #[ORM\Column(name: 'last_received_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastReceivedAt = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: FirmBankAccount::class)]
        #[ORM\JoinColumn(name: 'firm_bank_account_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly FirmBankAccount $account,
        string $tokenHash,
        string $tokenSealed,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->tokenHash = $this->checkedHash($tokenHash);
        $this->tokenSealed = $this->checkedSealed($tokenSealed);
    }

    /**
     * @param string $tokenHash   the lowercase hex SHA-256 of the token
     * @param string $tokenSealed the token, sealed
     */
    public static function open(FirmBankAccount $account, string $tokenHash, string $tokenSealed, \DateTimeImmutable $at): self
    {
        return new self($account, $tokenHash, $tokenSealed, $at);
    }

    /**
     * A new token in place of the old one, which is not found any more.
     */
    public function rotate(string $tokenHash, string $tokenSealed, \DateTimeImmutable $at): void
    {
        $tokenHash = $this->checkedHash($tokenHash);
        if (hash_equals($this->tokenHash, $tokenHash)) {
            throw new \DomainException('A new token must differ from the old one.');
        }
        $this->tokenHash = $tokenHash;
        $this->tokenSealed = $this->checkedSealed($tokenSealed);
        ++$this->generation;
        $this->rotatedAt = $at;
        $this->lastReceivedAt = null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->account->firm();
    }

    public function account(): FirmBankAccount
    {
        return $this->account;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function tokenSealed(): string
    {
        return $this->tokenSealed;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rotatedAt(): ?\DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    public function lastReceivedAt(): ?\DateTimeImmutable
    {
        return $this->lastReceivedAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function checkedHash(string $hash): string
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $hash)) {
            throw new \DomainException('A token hash is 64 lowercase hex digits.');
        }

        return $hash;
    }

    private function checkedSealed(string $sealed): string
    {
        if ('' === $sealed) {
            throw new \DomainException('The sealed token is missing.');
        }

        return $sealed;
    }
}
