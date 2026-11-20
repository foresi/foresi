<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\Id;

/**
 * A number an invoice held until an edit gave it another. It stays taken for good:
 * a number is never reused, not even by the invoice that gave it up.
 */
#[ORM\Entity]
#[ORM\Table(name: 'abandoned_number')]
#[ORM\Index(name: 'idx_abandoned_number_invoice', columns: ['invoice_id'])]
#[ORM\UniqueConstraint(name: 'uniq_abandoned_number', columns: ['number_key'])]
class AbandonedNumber implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'number_key', type: 'string', length: Invoice::MAX_NUMBER_LENGTH)]
    private string $numberKey;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Invoice::class)]
        #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Invoice $invoice,
        #[ORM\Column(type: 'string', length: Invoice::MAX_NUMBER_LENGTH)]
        private readonly string $number,
    ) {
        $this->id = Id::generate();
        $this->numberKey = NumberKey::of($number);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->invoice;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function numberKey(): string
    {
        return $this->numberKey;
    }
}
