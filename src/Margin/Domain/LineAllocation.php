<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;

/**
 * How the cost of one line of a document is split. Its key is the key of the
 * line in its document; when the line is gone, so is this.
 */
#[ORM\Entity]
#[ORM\Table(name: 'margin_line_allocation')]
#[ORM\UniqueConstraint(name: 'uniq_margin_line_allocation', columns: ['document_id', 'line_key'])]
class LineAllocation implements Audited
{
    public const int MAX_KEY = 64;

    /** @var array<mixed> */
    #[ORM\Column(name: 'allocation', type: 'json')]
    private array $allocation;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\Column(name: 'document_id', type: 'guid')]
        private readonly string $documentId,
        #[ORM\Column(name: 'line_key', length: self::MAX_KEY)]
        private readonly string $lineKey,
        Allocation $allocation,
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'updated_by', length: 64)]
        private string $updatedBy,
    ) {
        $this->allocation = $allocation->toArray();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function documentId(): string
    {
        return $this->documentId;
    }

    public function lineKey(): string
    {
        return $this->lineKey;
    }

    public function allocation(): Allocation
    {
        return Allocation::fromArray($this->allocation);
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updatedBy(): string
    {
        return $this->updatedBy;
    }

    public function change(Allocation $allocation, \DateTimeImmutable $at, string $by): void
    {
        $this->allocation = $allocation->toArray();
        $this->updatedAt = $at;
        $this->updatedBy = $by;
    }
}
