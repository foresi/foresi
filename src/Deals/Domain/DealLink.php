<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Domain\Id;

/**
 * One deal linked to another of the same client, such as a follow-up that
 * follows from the deal before it. A pair is linked once for each type.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_deal_link')]
#[ORM\UniqueConstraint(name: 'uniq_sales_deal_link', columns: ['from_deal_id', 'to_deal_id', 'link_type'])]
#[ORM\Index(name: 'idx_sales_deal_link_to', columns: ['to_deal_id'])]
class DealLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    public function __construct(
        #[ORM\Column(name: 'from_deal_id', type: 'guid')]
        private string $fromDealId,
        #[ORM\Column(name: 'to_deal_id', type: 'guid')]
        private string $toDealId,
        #[ORM\Column(name: 'link_type', type: 'string', length: 32, enumType: DealLinkType::class)]
        private DealLinkType $type,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function fromDealId(): string
    {
        return $this->fromDealId;
    }

    public function toDealId(): string
    {
        return $this->toDealId;
    }

    public function type(): DealLinkType
    {
        return $this->type;
    }

    /** Whether the deal is one of its two ends. */
    public function joins(string $dealId): bool
    {
        return $dealId === $this->fromDealId || $dealId === $this->toDealId;
    }
}
