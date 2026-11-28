<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * The part of one line's cost, in CZK without VAT, that belongs to one
 * recipient in one month. Derived, never typed: CostShareCalculation replaces
 * all shares of a document whenever it or its mapping changes. A null
 * recipient is the cost nobody has claimed yet.
 */
#[ORM\Entity]
#[ORM\Table(name: 'margin_cost_share')]
#[ORM\Index(name: 'idx_margin_cost_share_document', columns: ['document_id'])]
#[ORM\Index(name: 'idx_margin_cost_share_month', columns: ['month'])]
class CostShare
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\Column(name: 'document_id', type: 'guid')]
        private readonly string $documentId,
        #[ORM\Column(name: 'line_key', length: LineAllocation::MAX_KEY)]
        private readonly string $lineKey,
        #[ORM\Column(type: 'date_immutable')]
        private readonly \DateTimeImmutable $month,
        #[ORM\Column(length: 36, nullable: true)]
        private readonly ?string $recipient,
        #[ORM\Column(name: 'amount_czk_minor', type: 'bigint')]
        private readonly int $amountCzkMinor,
        #[ORM\Column(length: 16, enumType: CostShareSource::class)]
        private readonly CostShareSource $source,
        #[ORM\Column(name: 'planned_item_id', type: 'guid', nullable: true)]
        private readonly ?string $plannedItemId = null,
        #[ORM\Column(name: 'occurrence_on', type: 'date_immutable', nullable: true)]
        private readonly ?\DateTimeImmutable $occurrenceOn = null,
    ) {
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

    public function month(): \DateTimeImmutable
    {
        return $this->month;
    }

    public function recipient(): ?Recipient
    {
        return null === $this->recipient ? null : Recipient::fromString($this->recipient);
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountCzkMinor, Currency::of('CZK'));
    }

    public function source(): CostShareSource
    {
        return $this->source;
    }

    /**
     * The planned item whose mapping gave the share, for a share from a pairing.
     */
    public function plannedItemId(): ?string
    {
        return $this->plannedItemId;
    }

    /**
     * The day of the occurrence the document is paired with, for a share from a pairing.
     */
    public function occurrenceOn(): ?\DateTimeImmutable
    {
        return $this->occurrenceOn;
    }
}
