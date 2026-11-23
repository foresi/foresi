<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A contact person of the client the deal is talked through with, in the
 * order the deal lists them. The person is a contact of Party, by its id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_deal_contact')]
#[ORM\Index(name: 'idx_sales_deal_contact_person', columns: ['party_person_id'])]
class DealContact
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'deal_id', type: 'guid')]
        private string $dealId,
        #[ORM\Id]
        #[ORM\Column(name: 'party_person_id', type: 'guid')]
        private string $personId,
        #[ORM\Column(type: 'integer')]
        private int $position,
    ) {
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function dealId(): string
    {
        return $this->dealId;
    }

    public function personId(): string
    {
        return $this->personId;
    }

    public function position(): int
    {
        return $this->position;
    }
}
