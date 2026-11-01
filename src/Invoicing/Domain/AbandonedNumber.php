<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A number an invoice held until an edit gave it another. It stays taken for good:
 * a number is never reused, not even by the invoice that gave it up.
 */
#[ORM\Entity]
#[ORM\Table(name: 'abandoned_number')]
#[ORM\Index(name: 'idx_abandoned_number_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_abandoned_number_invoice', columns: ['invoice_id'])]
#[ORM\UniqueConstraint(name: 'uniq_abandoned_number', columns: ['legal_entity_id', 'number_key'])]
class AbandonedNumber implements TenantOwned, Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
    #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
    private readonly LegalEntity $firm;

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
        $this->firm = $invoice->firm();
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

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
