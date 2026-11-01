<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * An issued invoice: there is no draft, saving issues it. The content stays
 * editable, every change audited, with the customer and the issuer as frozen
 * copies. The firm, the VAT regime and the rounding are fixed at issue. Series and
 * number stay open until the document is delivered, and a number it gives up stays
 * taken as an AbandonedNumber. In the trash an invoice keeps its number and content
 * and takes no change until it is restored; a delivered invoice cannot go there.
 *
 * Payments, corrections and sending hang on the id of the invoice in their own
 * tables; deliveredAt is what sending sets. A number is unique in a firm, trash
 * included. Create invoices through IssueInvoice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice')]
#[ORM\Index(name: 'idx_invoice_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_invoice_firm_date', columns: ['tenant_id', 'legal_entity_id', 'issue_date'])]
#[ORM\Index(name: 'idx_invoice_series', columns: ['series_id'])]
#[ORM\Index(name: 'idx_invoice_customer', columns: ['customer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_invoice_number', columns: ['legal_entity_id', 'number_key'])]
class Invoice implements TenantOwned, Audited
{
    /** The longest format of a series with the longest sequence it can hold. */
    public const int MAX_NUMBER_LENGTH = 80;
    public const int MAX_ORDER_NUMBER_LENGTH = 64;
    public const int MAX_CONSTANT_SYMBOL_LENGTH = 10;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 32, enumType: DocumentKind::class)]
    private DocumentKind $kind;

    #[ORM\Column(name: 'number_key', type: 'string', length: self::MAX_NUMBER_LENGTH)]
    private string $numberKey;

    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'RESTRICT')]
    private Party $customerParty;

    #[ORM\Embedded(class: DocumentParty::class, columnPrefix: 'customer_')]
    private DocumentParty $customer;

    #[ORM\Column(name: 'exchange_rate', type: 'string', length: 32)]
    private string $exchangeRate = '1';

    #[ORM\Column(name: 'issue_date', type: 'date_immutable')]
    private \DateTimeImmutable $issueDate;

    #[ORM\Column(name: 'tax_point_date', type: 'date_immutable')]
    private \DateTimeImmutable $taxPointDate;

    #[ORM\Column(name: 'due_date', type: 'date_immutable')]
    private \DateTimeImmutable $dueDate;

    #[ORM\Column(name: 'payment_method', type: 'string', length: 32, enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod;

    #[ORM\Column(name: 'bank_account', type: 'string', length: 34, nullable: true)]
    private ?string $bankAccount;

    #[ORM\Column(name: 'variable_symbol', type: 'string', length: 10)]
    private string $variableSymbol;

    #[ORM\Column(name: 'constant_symbol', type: 'string', length: self::MAX_CONSTANT_SYMBOL_LENGTH)]
    private string $constantSymbol;

    #[ORM\Column(name: 'order_number', type: 'string', length: self::MAX_ORDER_NUMBER_LENGTH)]
    private string $orderNumber;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(name: 'text_before', type: 'text')]
    private string $textBefore;

    #[ORM\Column(name: 'text_after', type: 'text')]
    private string $textAfter;

    #[ORM\Column(type: 'string', length: 2, enumType: DocumentLanguage::class, options: ['fixed' => true])]
    private DocumentLanguage $language;

    #[ORM\Column(name: 'net_minor', type: 'bigint')]
    private int $netMinor = 0;

    #[ORM\Column(name: 'vat_minor', type: 'bigint')]
    private int $vatMinor = 0;

    #[ORM\Column(name: 'rounding_minor', type: 'bigint')]
    private int $roundingMinor = 0;

    #[ORM\Column(name: 'payable_minor', type: 'bigint')]
    private int $payableMinor = 0;

    #[ORM\Column(name: 'delivered_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(name: 'deleted_by', type: 'guid', nullable: true)]
    private ?string $deletedBy = null;

    #[ORM\Column(name: 'deleted_reason', type: 'text', nullable: true)]
    private ?string $deletedReason = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        #[ORM\ManyToOne(targetEntity: Series::class)]
        #[ORM\JoinColumn(name: 'series_id', nullable: false, onDelete: 'RESTRICT')]
        private Series $series,
        #[ORM\Column(name: 'number', type: 'string', length: self::MAX_NUMBER_LENGTH)]
        private string $number,
        #[ORM\Column(name: 'vat_regime', type: 'string', length: 16, enumType: VatRegime::class)]
        private readonly VatRegime $vatRegime,
        #[ORM\Column(type: 'string', length: 16, enumType: TotalRounding::class)]
        private readonly TotalRounding $rounding,
        #[ORM\Column(type: 'string', length: 3, enumType: Currency::class, options: ['fixed' => true])]
        private readonly Currency $currency,
        #[ORM\Embedded(class: DocumentParty::class, columnPrefix: 'issuer_')]
        private readonly DocumentParty $issuer,
        InvoiceChange $content,
        #[ORM\Column(name: 'created_by', type: 'guid', nullable: true)]
        private readonly ?string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->refuseForeignSeries($series);
        $this->id = Id::generate();
        $this->kind = $series->kind();
        $this->numberKey = NumberKey::of($this->number);
        $this->issueDate = $content->issueDate;
        $this->taxPointDate = $content->taxPointDate;
        $this->dueDate = $content->dueDate;
        $this->applyContent($content);
    }

    /**
     * @throws \DomainException for an invoice in the trash
     */
    public function change(InvoiceChange $change): void
    {
        $this->refuseTrashed();
        // A new object for the same day would count as a change and reach the audit log.
        $this->issueDate = $this->keepDay($this->issueDate, $change->issueDate);
        $this->taxPointDate = $this->keepDay($this->taxPointDate, $change->taxPointDate);
        $this->dueDate = $this->keepDay($this->dueDate, $change->dueDate);
        $this->applyContent($change);
    }

    public function assignTotals(DocumentTotals $totals): void
    {
        $this->netMinor = $totals->net->minor();
        $this->vatMinor = $totals->vat->minor();
        $this->roundingMinor = $totals->rounding->minor();
        $this->payableMinor = $totals->payable->minor();
    }

    /**
     * Whether saving these values would give the invoice another series or number.
     */
    public function isRenumberedBy(?string $seriesId, ?string $typedNumber): bool
    {
        return (null !== $seriesId && $seriesId !== $this->series->id())
            || (null !== $typedNumber && trim($typedNumber) !== $this->number);
    }

    /**
     * @return AbandonedNumber|null the number given up, to be stored; null when only
     *                              its punctuation or case changed, as the number is the same
     *
     * @throws \DomainException once delivered, for an invoice in the trash, or for a series of another firm
     */
    public function changeNumber(Series $series, string $number): ?AbandonedNumber
    {
        $this->refuseTrashed();
        if ($this->isDelivered()) {
            throw new \DomainException('Series and number are fixed once the document is delivered.');
        }
        $this->refuseForeignSeries($series);
        $key = NumberKey::of($number);
        $abandoned = $key === $this->numberKey ? null : new AbandonedNumber($this, $this->number);
        $this->series = $series;
        $this->number = $number;
        $this->numberKey = $key;

        return $abandoned;
    }

    /**
     * Repeatable: the first date stays.
     *
     * @throws \DomainException for an invoice in the trash
     */
    public function markDelivered(\DateTimeImmutable $at): void
    {
        $this->refuseTrashed();
        $this->deliveredAt ??= $at;
    }

    /**
     * @throws \DomainException once delivered, without a reason, or when it is in the trash already
     */
    public function trash(\DateTimeImmutable $at, ?string $userId, string $reason): void
    {
        $this->refuseTrashed();
        if ($this->isDelivered()) {
            throw new \DomainException('A delivered document cannot be deleted.');
        }
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \DomainException('Deleting a document needs a reason.');
        }
        $this->deletedAt = $at;
        $this->deletedBy = $userId;
        $this->deletedReason = $reason;
    }

    /**
     * The audit log keeps who deleted it, when and why.
     */
    public function restore(): void
    {
        $this->deletedAt = null;
        $this->deletedBy = null;
        $this->deletedReason = null;
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }

    public function isDelivered(): bool
    {
        return $this->deliveredAt instanceof \DateTimeImmutable;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function series(): Series
    {
        return $this->series;
    }

    public function kind(): DocumentKind
    {
        return $this->kind;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function numberKey(): string
    {
        return $this->numberKey;
    }

    public function vatRegime(): VatRegime
    {
        return $this->vatRegime;
    }

    public function rounding(): TotalRounding
    {
        return $this->rounding;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function exchangeRate(): string
    {
        return $this->exchangeRate;
    }

    public function customerParty(): Party
    {
        return $this->customerParty;
    }

    public function customer(): DocumentParty
    {
        return $this->customer;
    }

    public function issuer(): DocumentParty
    {
        return $this->issuer;
    }

    public function issueDate(): \DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function taxPointDate(): \DateTimeImmutable
    {
        return $this->taxPointDate;
    }

    public function dueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function paymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function bankAccount(): ?string
    {
        return $this->bankAccount;
    }

    public function variableSymbol(): string
    {
        return $this->variableSymbol;
    }

    public function constantSymbol(): string
    {
        return $this->constantSymbol;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function textBefore(): string
    {
        return $this->textBefore;
    }

    public function textAfter(): string
    {
        return $this->textAfter;
    }

    public function language(): DocumentLanguage
    {
        return $this->language;
    }

    public function net(): Money
    {
        return Money::ofMinor($this->netMinor, $this->currency);
    }

    public function vat(): Money
    {
        return Money::ofMinor($this->vatMinor, $this->currency);
    }

    /**
     * The exact total, without the rounding of the payable amount.
     */
    public function gross(): Money
    {
        return $this->net()->plus($this->vat());
    }

    public function totalRounding(): Money
    {
        return Money::ofMinor($this->roundingMinor, $this->currency);
    }

    public function payable(): Money
    {
        return Money::ofMinor($this->payableMinor, $this->currency);
    }

    public function deliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function deletedBy(): ?string
    {
        return $this->deletedBy;
    }

    public function deletedReason(): ?string
    {
        return $this->deletedReason;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function applyContent(InvoiceChange $content): void
    {
        $this->customerParty = $content->customerParty;
        $this->customer = $content->customer;
        $this->paymentMethod = $content->paymentMethod;
        $this->bankAccount = $content->bankAccount;
        $this->variableSymbol = $content->variableSymbol;
        $this->constantSymbol = $content->constantSymbol;
        $this->orderNumber = $content->orderNumber;
        $this->description = $content->description;
        $this->textBefore = $content->textBefore;
        $this->textAfter = $content->textAfter;
        $this->language = $content->language;
    }

    private function keepDay(\DateTimeImmutable $stored, \DateTimeImmutable $given): \DateTimeImmutable
    {
        return $stored->format('Y-m-d') === $given->format('Y-m-d') ? $stored : $given;
    }

    private function refuseTrashed(): void
    {
        if ($this->isTrashed()) {
            throw new \DomainException('A document in the trash takes no change until it is restored.');
        }
    }

    private function refuseForeignSeries(Series $series): void
    {
        if ($series->firm()->id() !== $this->firm->id()) {
            throw new \DomainException('A document takes a series of its own firm.');
        }
    }
}
