<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;

/**
 * The quick assignment of a whole document to one recipient, for every line
 * that has no mapping of its own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'margin_document_allocation')]
#[ORM\UniqueConstraint(name: 'uniq_margin_document_allocation', columns: ['document_id'])]
class DocumentAllocation implements Audited
{
    #[ORM\Column(name: 'recipient', length: 36)]
    private string $recipient;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\Column(name: 'document_id', type: 'guid')]
        private readonly string $documentId,
        Recipient $recipient,
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'updated_by', length: 64)]
        private string $updatedBy,
    ) {
        $this->recipient = $recipient->toString();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function documentId(): string
    {
        return $this->documentId;
    }

    public function recipient(): Recipient
    {
        return Recipient::fromString($this->recipient);
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updatedBy(): string
    {
        return $this->updatedBy;
    }

    public function change(Recipient $recipient, \DateTimeImmutable $at, string $by): void
    {
        $this->recipient = $recipient->toString();
        $this->updatedAt = $at;
        $this->updatedBy = $by;
    }
}
