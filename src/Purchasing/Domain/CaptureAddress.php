<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditExcluded;
use Foresi\Shared\Domain\Id;

/**
 * The address a person forwards received documents to: their own name for it,
 * and the id of the inbound address Mail keeps (the token never leaves Mail).
 * One person has one active address at a time; a revoked one stays as history.
 * Replacing the address keeps this row, as Mail keeps its row, so the name and
 * the history stay with the person's address.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchasing_capture_address')]
#[ORM\UniqueConstraint(name: 'uniq_purchasing_capture_address_mail', columns: ['mail_address_id'])]
#[ORM\UniqueConstraint(name: 'uniq_purchasing_capture_address_active_owner', columns: ['active_owner_user_id'])]
class CaptureAddress implements Audited
{
    public const int MAX_LABEL = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_LABEL)]
    private string $label;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    // The owner while the address takes in mail, empty once revoked: the unique constraint on it keeps
    // one active address per person on the database too, where two creates at once would meet.
    #[AuditExcluded]
    #[ORM\Column(name: 'active_owner_user_id', type: 'guid', nullable: true)]
    private ?string $activeOwnerUserId;

    /** Written as mail for the address is taken in; not an edit of the person, so left out of the audit log. */
    #[AuditExcluded]
    #[ORM\Column(name: 'last_received_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastReceivedAt = null;

    public function __construct(
        #[ORM\Column(name: 'owner_user_id', type: 'guid')]
        private string $ownerUserId,
        #[ORM\Column(name: 'mail_address_id', type: 'guid')]
        private string $mailAddressId,
        string $label,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->label = self::checkedLabel($label);
        $this->activeOwnerUserId = $ownerUserId;
    }

    /**
     * @throws CaptureAddressRefused
     */
    public static function checkedLabel(string $label): string
    {
        $label = trim($label);
        if ('' === $label) {
            throw new CaptureAddressRefused(CaptureAddressRefused::LABEL_REQUIRED);
        }
        if (mb_strlen($label) > self::MAX_LABEL) {
            throw new CaptureAddressRefused(CaptureAddressRefused::LABEL_TOO_LONG);
        }

        return $label;
    }

    /**
     * @throws CaptureAddressRefused
     */
    public function rename(string $label): void
    {
        $this->label = self::checkedLabel($label);
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
        $this->activeOwnerUserId = null;
    }

    /**
     * Mail for the address was taken in.
     */
    public function received(\DateTimeImmutable $at): void
    {
        $this->lastReceivedAt = $at;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof \DateTimeImmutable;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ownerUserId(): string
    {
        return $this->ownerUserId;
    }

    public function mailAddressId(): string
    {
        return $this->mailAddressId;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastReceivedAt(): ?\DateTimeImmutable
    {
        return $this->lastReceivedAt;
    }
}
