<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditExcluded;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Shared\Domain\Id;

/**
 * The inbound address of one bank account: where the bank's payment
 * notifications are sent. The address itself, its token stored as a hash and a
 * sealed copy, is Mail's inbound address of the purpose bank_feed, named here
 * by its id; a new token shows in the audit log as a new generation. The time
 * of the last message does not go to the audit log.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bank_feed')]
#[ORM\UniqueConstraint(name: 'uniq_bank_feed_account', columns: ['firm_bank_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_bank_feed_address', columns: ['mail_inbound_address_id'])]
class BankFeed implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

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
        #[ORM\Column(name: 'mail_inbound_address_id', type: 'guid')]
        private readonly string $addressId,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($addressId)) {
            throw new \DomainException('A bank feed names its inbound address.');
        }
        $this->id = Id::generate();
    }

    /**
     * @param string $addressId the id of Mail's inbound address
     */
    public static function open(FirmBankAccount $account, string $addressId, \DateTimeImmutable $at): self
    {
        return new self($account, $addressId, $at);
    }

    /**
     * Mail gave the address a new token in place of the old one, which is not found any more.
     */
    public function rotated(\DateTimeImmutable $at): void
    {
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
        return $this->account;
    }

    public function account(): FirmBankAccount
    {
        return $this->account;
    }

    public function addressId(): string
    {
        return $this->addressId;
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
}
