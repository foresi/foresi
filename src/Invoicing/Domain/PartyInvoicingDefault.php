<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Party\Domain\Party;

/**
 * The invoicing defaults of one party, in a row of Invoicing beside the party.
 * Its id is the party's id. Its changes are part of the party's history, under
 * the same name the party's own columns had.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoicing_party_default')]
class PartyInvoicingDefault implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Embedded(class: InvoicingDefaults::class, columnPrefix: false)]
    private InvoicingDefaults $invoicingDefaults;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: false, onDelete: 'CASCADE')]
        private Party $party,
        ?InvoicingDefaults $defaults = null,
    ) {
        $this->id = $party->id();
        $this->invoicingDefaults = $defaults ?? InvoicingDefaults::standard();
    }

    public function partyId(): string
    {
        return $this->id;
    }

    public function defaults(): InvoicingDefaults
    {
        return $this->invoicingDefaults;
    }

    public function change(InvoicingDefaults $defaults): void
    {
        // An equal value stays the same object, so the flush records no change.
        if (!$defaults->equals($this->invoicingDefaults)) {
            $this->invoicingDefaults = $defaults;
        }
    }

    public function auditRoot(): object
    {
        return $this->party;
    }
}
