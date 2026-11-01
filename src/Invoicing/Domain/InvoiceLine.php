<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line of an invoice, audited under it. Amounts are in the currency of the
 * invoice. The rate is the value on the tax point, so a later rate change leaves
 * the line alone; the kind tells which rate it was. Quantity is held as whole
 * ten-thousandths, percentages as their two-decimal text.
 *
 * A line keeps its position for good: reordering a document rewrites the content
 * of the lines in place, and only the last lines come and go. So the unique index
 * on the position never sees two rows at one position, in any order of statements,
 * and needs no deferred check, which SQLite and PostgreSQL would not share.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_line')]
#[ORM\Index(name: 'idx_invoice_line_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_invoice_line_invoice', columns: ['invoice_id'])]
#[ORM\UniqueConstraint(name: 'uniq_invoice_line_position', columns: ['invoice_id', 'position'])]
class InvoiceLine implements TenantOwned, Audited, AuditRooted
{
    public const int MAX_UNIT_LENGTH = 32;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column(type: 'bigint')]
    private int $quantity;

    #[ORM\Column(type: 'string', length: self::MAX_UNIT_LENGTH)]
    private string $unit;

    #[ORM\Column(name: 'price_minor', type: 'bigint')]
    private int $priceMinor;

    #[ORM\Column(name: 'price_type', type: 'string', length: 16, enumType: PriceType::class)]
    private PriceType $priceType;

    #[ORM\Column(type: 'string', length: 16, enumType: TaxTreatment::class)]
    private TaxTreatment $treatment;

    #[ORM\Column(name: 'supply_code', type: 'string', length: 8, nullable: true)]
    private ?string $supplyCode;

    #[ORM\Column(name: 'rate_kind', type: 'string', length: 16, nullable: true, enumType: VatRateKind::class)]
    private ?VatRateKind $rateKind;

    #[ORM\Column(type: 'string', length: 6, nullable: true)]
    private ?string $rate;

    #[ORM\Column(type: 'string', length: 6)]
    private string $discount;

    #[ORM\Column(name: 'unit_net_minor', type: 'bigint')]
    private int $unitNetMinor;

    #[ORM\Column(name: 'net_minor', type: 'bigint')]
    private int $netMinor;

    #[ORM\Column(name: 'vat_minor', type: 'bigint')]
    private int $vatMinor;

    #[ORM\Column(name: 'gross_minor', type: 'bigint')]
    private int $grossMinor;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Invoice::class)]
        #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Invoice $invoice,
        #[ORM\Column(type: 'integer')]
        private readonly int $position,
        string $text,
        string $unit,
        ?VatRateKind $rateKind,
        ?string $supplyCode,
        LineValues $values,
        LineAmounts $amounts,
    ) {
        $this->id = Id::generate();
        $this->revise($text, $unit, $rateKind, $supplyCode, $values, $amounts);
    }

    /**
     * Only a real difference reaches the audit log, so a line saved unchanged leaves no entry.
     *
     * @throws \DomainException for a line of an invoice in the trash
     */
    public function revise(string $text, string $unit, ?VatRateKind $rateKind, ?string $supplyCode, LineValues $values, LineAmounts $amounts): void
    {
        if ($this->invoice->isTrashed()) {
            throw new \DomainException('A document in the trash takes no change until it is restored.');
        }
        $this->text = $text;
        $this->quantity = $values->quantity->tenThousandths();
        $this->unit = $unit;
        $this->priceMinor = $values->price->minor();
        $this->priceType = $values->priceType;
        $this->treatment = $values->treatment;
        $this->supplyCode = $supplyCode;
        $this->rateKind = $rateKind;
        $this->rate = $values->rate?->decimal();
        $this->discount = $values->discount->decimal();
        $this->unitNetMinor = $amounts->unitNet->minor();
        $this->netMinor = $amounts->net->minor();
        $this->vatMinor = $amounts->vat->minor();
        $this->grossMinor = $amounts->gross->minor();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->invoice;
    }

    public function invoice(): Invoice
    {
        return $this->invoice;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function quantity(): Quantity
    {
        return Quantity::ofTenThousandths($this->quantity);
    }

    public function unit(): string
    {
        return $this->unit;
    }

    public function price(): Money
    {
        return $this->money($this->priceMinor);
    }

    public function priceType(): PriceType
    {
        return $this->priceType;
    }

    public function treatment(): TaxTreatment
    {
        return $this->treatment;
    }

    public function supplyCode(): ?string
    {
        return $this->supplyCode;
    }

    public function rateKind(): ?VatRateKind
    {
        return $this->rateKind;
    }

    public function rate(): ?Percent
    {
        return null === $this->rate ? null : Percent::fromString($this->rate);
    }

    public function discount(): Percent
    {
        return Percent::fromString($this->discount);
    }

    public function unitNet(): Money
    {
        return $this->money($this->unitNetMinor);
    }

    public function net(): Money
    {
        return $this->money($this->netMinor);
    }

    public function vat(): Money
    {
        return $this->money($this->vatMinor);
    }

    public function gross(): Money
    {
        return $this->money($this->grossMinor);
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function money(int $minor): Money
    {
        return Money::ofMinor($minor, $this->invoice->currency());
    }
}
