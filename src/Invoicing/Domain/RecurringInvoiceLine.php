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
 * A line of a recurring template, audited under it, as the person typed it: the
 * price as entered and the kind of rate, never a rate or an amount, which each
 * occurrence works out on its own tax point. The text may hold period variables.
 * Like an invoice line it keeps its position for good, so editing rewrites the
 * lines in place and only the last ones come and go.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recurring_invoice_line')]
#[ORM\Index(name: 'idx_recurring_invoice_line_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_recurring_invoice_line_template', columns: ['recurring_invoice_id'])]
#[ORM\UniqueConstraint(name: 'uniq_recurring_invoice_line_position', columns: ['recurring_invoice_id', 'position'])]
class RecurringInvoiceLine implements TenantOwned, Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column(type: 'bigint')]
    private int $quantity;

    #[ORM\Column(type: 'string', length: InvoiceLine::MAX_UNIT_LENGTH)]
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

    #[ORM\Column(type: 'string', length: 6)]
    private string $discount;

    /**
     * @throws \DomainException for a price in another currency than the template's
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: RecurringInvoice::class)]
        #[ORM\JoinColumn(name: 'recurring_invoice_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly RecurringInvoice $template,
        #[ORM\Column(type: 'integer')]
        private readonly int $position,
        string $text,
        Quantity $quantity,
        string $unit,
        Money $price,
        PriceType $priceType,
        TaxTreatment $treatment,
        ?VatRateKind $rateKind,
        ?string $supplyCode,
        Percent $discount,
    ) {
        $this->id = Id::generate();
        $this->revise($text, $quantity, $unit, $price, $priceType, $treatment, $rateKind, $supplyCode, $discount);
    }

    /**
     * Only a real difference reaches the audit log, so a line saved unchanged leaves no entry.
     *
     * @throws \DomainException for a price in another currency than the template's
     */
    public function revise(string $text, Quantity $quantity, string $unit, Money $price, PriceType $priceType, TaxTreatment $treatment, ?VatRateKind $rateKind, ?string $supplyCode, Percent $discount): void
    {
        if ($price->currency() !== $this->template->currency()) {
            throw new \DomainException('A line of a template is priced in the currency of the template.');
        }
        $this->text = $text;
        $this->quantity = $quantity->tenThousandths();
        $this->unit = $unit;
        $this->priceMinor = $price->minor();
        $this->priceType = $priceType;
        $this->treatment = $treatment;
        $this->rateKind = $rateKind;
        $this->supplyCode = $supplyCode;
        $this->discount = $discount->decimal();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->template;
    }

    public function template(): RecurringInvoice
    {
        return $this->template;
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
        return Money::ofMinor($this->priceMinor, $this->template->currency());
    }

    public function priceType(): PriceType
    {
        return $this->priceType;
    }

    public function treatment(): TaxTreatment
    {
        return $this->treatment;
    }

    public function rateKind(): ?VatRateKind
    {
        return $this->rateKind;
    }

    public function supplyCode(): ?string
    {
        return $this->supplyCode;
    }

    public function discount(): Percent
    {
        return Percent::fromString($this->discount);
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
