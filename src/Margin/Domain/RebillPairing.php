<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;

/**
 * An invoice line paired with the occurrence of a planned item it rebills:
 * made when the invoice is issued from a template whose line is linked
 * (manual false), or by a person for a hand-written line (manual true). The
 * invoice and its customer are kept as they were when the line was paired, so
 * "an invoice of this client rebilled it" is answered without asking Invoicing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'margin_rebill_pairing')]
#[ORM\UniqueConstraint(name: 'uniq_margin_rebill_pairing_line', columns: ['invoice_line_id'])]
#[ORM\Index(name: 'idx_margin_rebill_pairing_occurrence', columns: ['planned_item_id', 'occurrence_on'])]
class RebillPairing implements Audited
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\Column(name: 'invoice_line_id', type: 'guid')]
        private readonly string $invoiceLineId,
        #[ORM\Column(name: 'invoice_id', type: 'guid')]
        private readonly string $invoiceId,
        #[ORM\Column(name: 'customer_id', type: 'guid')]
        private readonly string $customerId,
        #[ORM\Column(name: 'planned_item_id', type: 'guid')]
        private readonly string $plannedItemId,
        #[ORM\Column(name: 'occurrence_on', type: 'date_immutable')]
        private readonly \DateTimeImmutable $occurrenceOn,
        #[ORM\Column(name: 'manual', type: 'boolean')]
        private readonly bool $manual,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function invoiceLineId(): string
    {
        return $this->invoiceLineId;
    }

    public function invoiceId(): string
    {
        return $this->invoiceId;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function plannedItemId(): string
    {
        return $this->plannedItemId;
    }

    public function occurrenceOn(): \DateTimeImmutable
    {
        return $this->occurrenceOn;
    }

    public function manual(): bool
    {
        return $this->manual;
    }
}
