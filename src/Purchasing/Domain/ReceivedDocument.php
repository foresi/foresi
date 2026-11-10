<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A document a firm received: a supplier's invoice, a receipt, a credit note, an
 * advance request or any other document of a cost (ADR 0028). It starts as a
 * draft, usually an uploaded file, and is recorded once complete; every change
 * is audited. Recording needs the evidence number of the firm's series, which
 * the document keeps for good, apart from the number the supplier gave it.
 *
 * The supplier is a contact with a frozen copy of what the document says about
 * it. The VAT recap holds the VAT per rate in the document's currency and in
 * CZK; the totals beside it are its sums, kept for lists and reports. The
 * deduction period and the latest deduction date follow from the treatment, the
 * tax point and the received date on every save.
 *
 * The original file is an attachment of the kind ATTACHMENT_KIND, named by id; a
 * credit note names the document it corrects by id. In the trash a document
 * keeps its number and content and takes no change until it is restored.
 */
#[ORM\Entity]
#[ORM\Table(name: 'received_document')]
#[ORM\Index(name: 'idx_received_document_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_received_document_firm_received', columns: ['tenant_id', 'legal_entity_id', 'received_date'])]
#[ORM\Index(name: 'idx_received_document_firm_deduction', columns: ['tenant_id', 'legal_entity_id', 'deduction_period'])]
#[ORM\Index(name: 'idx_received_document_supplier', columns: ['supplier_id'])]
#[ORM\Index(name: 'idx_received_document_original', columns: ['original_document_id'])]
#[ORM\UniqueConstraint(name: 'uniq_received_document_evidence', columns: ['legal_entity_id', 'evidence_number'])]
class ReceivedDocument implements TenantOwned, Audited
{
    /** The owner kind of the original file in the attachment store. */
    public const string ATTACHMENT_KIND = 'received_document';
    public const int MAX_EVIDENCE_NUMBER_LENGTH = 64;
    public const int MAX_SUPPLIER_NUMBER_LENGTH = 64;
    public const int MAX_REASON_LENGTH = 500;
    private const string VARIABLE_SYMBOL = '/^\d{1,10}$/D';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    // Goes up with every save, so a form can tell it was opened before the last one. A technical
    // value, so left out of the audit log: a save that changes nothing leaves no entry.
    #[AuditExcluded]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'string', length: 16, enumType: ReceivedDocumentState::class)]
    private ReceivedDocumentState $state = ReceivedDocumentState::Draft;

    #[ORM\Column(type: 'string', length: 32, enumType: ReceivedDocumentKind::class)]
    private ReceivedDocumentKind $kind;

    #[ORM\Column(type: 'string', length: 32, enumType: PurchaseTaxTreatment::class)]
    private PurchaseTaxTreatment $treatment;

    // Given when the document is first recorded; a draft has none.
    #[ORM\Column(name: 'evidence_number', type: 'string', length: self::MAX_EVIDENCE_NUMBER_LENGTH, nullable: true)]
    private ?string $evidenceNumber = null;

    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Party $supplierParty;

    #[ORM\Embedded(class: SupplierSnapshot::class, columnPrefix: 'supplier_')]
    private SupplierSnapshot $supplier;

    // The number the supplier gave the document, which the VAT reports name.
    #[ORM\Column(name: 'supplier_document_number', type: 'string', length: self::MAX_SUPPLIER_NUMBER_LENGTH)]
    private string $supplierNumber;

    #[ORM\Column(name: 'variable_symbol', type: 'string', length: 10)]
    private string $variableSymbol;

    #[ORM\Column(name: 'issue_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $issueDate;

    // The issue date when the document names no other; set so on recording.
    #[ORM\Column(name: 'tax_point_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $taxPointDate;

    #[ORM\Column(name: 'due_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueDate;

    #[ORM\Column(name: 'received_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $receivedDate;

    #[ORM\Column(type: 'string', length: 3, enumType: Currency::class, options: ['fixed' => true])]
    private Currency $currency;

    // What the document asks to be paid, in its currency.
    #[ORM\Column(name: 'total_minor', type: 'bigint', nullable: true)]
    private ?int $totalMinor;

    #[ORM\Column(name: 'base_minor', type: 'bigint')]
    private int $baseMinor;

    #[ORM\Column(name: 'vat_minor', type: 'bigint')]
    private int $vatMinor;

    // NULL while a document in another currency has no exchange rate.
    #[ORM\Column(name: 'base_czk_minor', type: 'bigint', nullable: true)]
    private ?int $baseCzkMinor;

    #[ORM\Column(name: 'vat_czk_minor', type: 'bigint', nullable: true)]
    private ?int $vatCzkMinor;

    // NULL while a document in another currency has none; always 1 on a CZK document.
    #[ORM\Column(name: 'exchange_rate', type: 'string', length: ExchangeRate::MAX_LENGTH, nullable: true)]
    private ?string $exchangeRate;

    // The day the rate was published for, as its source says.
    #[ORM\Column(name: 'exchange_rate_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $exchangeRateDate;

    /** @var list<array{rate: string, base: int, vat: int, stated_vat_czk: ?int, base_czk: ?int, vat_czk: ?int}> */
    #[ORM\Column(name: 'vat_recap', type: 'json')]
    private array $vatRecap;

    // The first day of the first month the VAT can be deducted in.
    #[ORM\Column(name: 'deduction_period', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $deductionPeriod;

    #[ORM\Column(name: 'latest_deduction_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $latestDeductionDate;

    #[ORM\Column(name: 'original_attachment_id', type: 'guid', nullable: true)]
    private ?string $originalAttachmentId = null;

    // Only a credit note names the received document it corrects.
    #[ORM\Column(name: 'original_document_id', type: 'guid', nullable: true)]
    private ?string $originalDocumentId;

    #[ORM\Column(name: 'recorded_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $recordedAt = null;

    #[ORM\Column(name: 'recorded_by', type: 'guid', nullable: true)]
    private ?string $recordedBy = null;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(name: 'deleted_by', type: 'guid', nullable: true)]
    private ?string $deletedBy = null;

    #[ORM\Column(name: 'deleted_reason', type: 'text', nullable: true)]
    private ?string $deletedReason = null;

    /**
     * A draft with these values; it checks what fits together, not what is missing.
     *
     * @throws ReceivedDocumentRefused when the values do not fit together
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        ReceivedDocumentContent $content,
        #[ORM\Column(name: 'created_by', type: 'guid', nullable: true)]
        private readonly ?string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->apply($content, false);
    }

    /**
     * Saves a draft, complete or not.
     *
     * @throws ReceivedDocumentRefused in the trash, once recorded, or when the values do not fit together
     */
    public function saveDraft(ReceivedDocumentContent $content): void
    {
        $this->refuseTrashed();
        if (ReceivedDocumentState::Recorded === $this->state) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::RECORDED);
        }
        $this->apply($content, false);
        ++$this->version;
    }

    /**
     * Records a draft, or saves a recorded document again; both need complete values.
     *
     * @throws ReceivedDocumentRefused in the trash, without an evidence number, or for values incomplete or not fitting together
     */
    public function record(ReceivedDocumentContent $content, ?string $userId, \DateTimeImmutable $at): void
    {
        $this->refuseTrashed();
        if (null === $this->evidenceNumber) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::EVIDENCE_NUMBER_MISSING);
        }
        $this->apply($content, true);
        if (ReceivedDocumentState::Draft === $this->state) {
            $this->state = ReceivedDocumentState::Recorded;
            $this->recordedAt = $at;
            $this->recordedBy = $userId;
        }
        ++$this->version;
    }

    /**
     * Once, from the firm's evidence series, before the document is first recorded.
     *
     * @throws ReceivedDocumentRefused in the trash, when it has a number already, or for an empty or too long one
     */
    public function assignEvidenceNumber(string $number): void
    {
        $this->refuseTrashed();
        if (null !== $this->evidenceNumber) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::EVIDENCE_NUMBER_FIXED);
        }
        $number = trim($number);
        if ('' === $number || \strlen($number) > self::MAX_EVIDENCE_NUMBER_LENGTH) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::EVIDENCE_NUMBER_INVALID);
        }
        $this->evidenceNumber = $number;
    }

    /**
     * Names the stored original file. A draft may take another one; a recorded
     * document keeps the file it was recorded from.
     *
     * @throws ReceivedDocumentRefused in the trash, for an id that is none, or for another file once recorded
     */
    public function attachOriginal(string $attachmentId): void
    {
        $this->refuseTrashed();
        if (!Id::isValid($attachmentId)) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::ORIGINAL_FILE_INVALID);
        }
        if ($attachmentId === $this->originalAttachmentId) {
            return;
        }
        if (ReceivedDocumentState::Recorded === $this->state && null !== $this->originalAttachmentId) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::ORIGINAL_FILE_FIXED);
        }
        $this->originalAttachmentId = $attachmentId;
        ++$this->version;
    }

    /**
     * @throws ReceivedDocumentRefused when it is in the trash already, or without a usable reason
     */
    public function trash(\DateTimeImmutable $at, ?string $userId, string $reason): void
    {
        $this->refuseTrashed();
        $reason = trim($reason);
        if ('' === $reason) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::REASON_MISSING);
        }
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::REASON_TOO_LONG);
        }
        $this->deletedAt = $at;
        $this->deletedBy = $userId;
        $this->deletedReason = $reason;
        ++$this->version;
    }

    /**
     * The audit log keeps who deleted it, when and why.
     */
    public function restore(): void
    {
        if (!$this->isTrashed()) {
            return;
        }
        $this->deletedAt = null;
        $this->deletedBy = null;
        $this->deletedReason = null;
        ++$this->version;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function state(): ReceivedDocumentState
    {
        return $this->state;
    }

    public function isRecorded(): bool
    {
        return ReceivedDocumentState::Recorded === $this->state;
    }

    public function kind(): ReceivedDocumentKind
    {
        return $this->kind;
    }

    public function treatment(): PurchaseTaxTreatment
    {
        return $this->treatment;
    }

    public function evidenceNumber(): ?string
    {
        return $this->evidenceNumber;
    }

    public function supplierParty(): ?Party
    {
        return $this->supplierParty;
    }

    public function supplier(): SupplierSnapshot
    {
        return $this->supplier;
    }

    public function supplierNumber(): string
    {
        return $this->supplierNumber;
    }

    public function variableSymbol(): string
    {
        return $this->variableSymbol;
    }

    public function issueDate(): ?\DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function taxPointDate(): ?\DateTimeImmutable
    {
        return $this->taxPointDate;
    }

    public function dueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function receivedDate(): ?\DateTimeImmutable
    {
        return $this->receivedDate;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function total(): ?Money
    {
        return null === $this->totalMinor ? null : Money::ofMinor($this->totalMinor, $this->currency);
    }

    public function base(): Money
    {
        return Money::ofMinor($this->baseMinor, $this->currency);
    }

    public function vat(): Money
    {
        return Money::ofMinor($this->vatMinor, $this->currency);
    }

    public function baseCzk(): ?Money
    {
        return null === $this->baseCzkMinor ? null : Money::ofMinor($this->baseCzkMinor, Currency::CZK);
    }

    public function vatCzk(): ?Money
    {
        return null === $this->vatCzkMinor ? null : Money::ofMinor($this->vatCzkMinor, Currency::CZK);
    }

    public function exchangeRate(): ?ExchangeRate
    {
        return null === $this->exchangeRate ? null : ExchangeRate::of($this->exchangeRate);
    }

    public function exchangeRateDate(): ?\DateTimeImmutable
    {
        return $this->exchangeRateDate;
    }

    public function vatRecap(): VatRecap
    {
        return VatRecap::fromArray($this->vatRecap, $this->currency);
    }

    public function deductionPeriod(): ?\DateTimeImmutable
    {
        return $this->deductionPeriod;
    }

    public function latestDeductionDate(): ?\DateTimeImmutable
    {
        return $this->latestDeductionDate;
    }

    public function originalAttachmentId(): ?string
    {
        return $this->originalAttachmentId;
    }

    public function originalDocumentId(): ?string
    {
        return $this->originalDocumentId;
    }

    public function recordedAt(): ?\DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function recordedBy(): ?string
    {
        return $this->recordedBy;
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
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

    /**
     * @throws ReceivedDocumentRefused
     */
    private function apply(ReceivedDocumentContent $content, bool $complete): void
    {
        $this->refuseUnfit($content);
        if ($complete) {
            $this->refuseIncomplete($content);
        }
        $rate = Currency::CZK === $content->currency ? ExchangeRate::one() : $content->exchangeRate;
        $recap = VatRecap::of($content->vatRecap, $content->currency, $rate, $content->treatment);
        // A tax point the document does not name is its issue date (ADR 0028).
        $taxPoint = $complete ? ($content->taxPointDate ?? $content->issueDate) : $content->taxPointDate;
        $deduction = DeductionDates::of($content->treatment, $taxPoint ?? $content->issueDate, $content->receivedDate);

        $this->kind = $content->kind;
        $this->treatment = $content->treatment;
        $this->supplierParty = $content->supplierParty;
        $this->supplier = $content->supplier;
        $this->supplierNumber = trim($content->supplierNumber);
        $this->variableSymbol = trim($content->variableSymbol);
        // A new object for the same day would count as a change and reach the audit log. The
        // first save runs in the constructor, before the dates hold anything, hence the ??.
        $this->issueDate = $this->keepDay($this->issueDate ?? null, $content->issueDate);
        $this->taxPointDate = $this->keepDay($this->taxPointDate ?? null, $taxPoint);
        $this->dueDate = $this->keepDay($this->dueDate ?? null, $content->dueDate);
        $this->receivedDate = $this->keepDay($this->receivedDate ?? null, $content->receivedDate);
        $this->currency = $content->currency;
        $this->totalMinor = $content->total?->minor();
        $this->exchangeRate = $rate?->toString();
        $this->exchangeRateDate = Currency::CZK === $content->currency ? null : $this->keepDay($this->exchangeRateDate ?? null, $content->exchangeRateDate);
        $this->vatRecap = $recap->toArray();
        $this->baseMinor = $recap->base()->minor();
        $this->vatMinor = $recap->vat()->minor();
        $this->baseCzkMinor = $recap->baseCzk()?->minor();
        $this->vatCzkMinor = $recap->vatCzk()?->minor();
        $this->deductionPeriod = $this->keepDay($this->deductionPeriod ?? null, $deduction->periodStart);
        $this->latestDeductionDate = $this->keepDay($this->latestDeductionDate ?? null, $deduction->latestDate);
        $this->originalDocumentId = $content->originalDocumentId;
    }

    /**
     * What never fits together, in a draft or a recorded document.
     *
     * @throws ReceivedDocumentRefused
     */
    private function refuseUnfit(ReceivedDocumentContent $content): void
    {
        if (!$content->kind->carriesVat() && PurchaseTaxTreatment::NotVatDocument !== $content->treatment) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::TREATMENT_FOR_KIND);
        }
        // A simplified document is never allowed where the recipient owes the tax (ADR 0028).
        if (ReceivedDocumentKind::SimplifiedReceipt === $content->kind && $content->treatment->isSelfAssessed()) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::SIMPLIFIED_SELF_ASSESSED);
        }
        if (null !== $content->originalDocumentId) {
            if (ReceivedDocumentKind::CreditNote !== $content->kind) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::ORIGINAL_NOT_CREDIT_NOTE);
            }
            if (!Id::isValid($content->originalDocumentId) || $content->originalDocumentId === $this->id) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::ORIGINAL_INVALID);
            }
        }
        if (mb_strlen(trim($content->supplierNumber)) > self::MAX_SUPPLIER_NUMBER_LENGTH) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::SUPPLIER_NUMBER_TOO_LONG);
        }
        $symbol = trim($content->variableSymbol);
        if ('' !== $symbol && 1 !== preg_match(self::VARIABLE_SYMBOL, $symbol)) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::VARIABLE_SYMBOL);
        }
        if (Currency::CZK === $content->currency && $content->exchangeRate instanceof ExchangeRate && !$content->exchangeRate->isOne()) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::CZK_RATE_NOT_ONE);
        }
        if ($content->total instanceof Money && $content->total->currency() !== $content->currency) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::CURRENCY_MISMATCH);
        }
    }

    /**
     * What a recorded document needs.
     *
     * @throws ReceivedDocumentRefused
     */
    private function refuseIncomplete(ReceivedDocumentContent $content): void
    {
        $missing = match (true) {
            !$content->supplierParty instanceof Party => ReceivedDocumentRefused::SUPPLIER_MISSING,
            !$content->issueDate instanceof \DateTimeImmutable => ReceivedDocumentRefused::ISSUE_DATE_MISSING,
            !$content->receivedDate instanceof \DateTimeImmutable => ReceivedDocumentRefused::RECEIVED_DATE_MISSING,
            !$content->total instanceof Money => ReceivedDocumentRefused::TOTAL_MISSING,
            $content->kind->needsVatRecap() && [] === $content->vatRecap => ReceivedDocumentRefused::VAT_RECAP_MISSING,
            ReceivedDocumentKind::CreditNote === $content->kind && null === $content->originalDocumentId => ReceivedDocumentRefused::ORIGINAL_MISSING,
            Currency::CZK !== $content->currency && !$content->exchangeRate instanceof ExchangeRate => ReceivedDocumentRefused::EXCHANGE_RATE_MISSING,
            default => null,
        };
        if (null !== $missing) {
            throw new ReceivedDocumentRefused($missing);
        }
    }

    private function refuseTrashed(): void
    {
        if ($this->isTrashed()) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::TRASHED);
        }
    }

    /**
     * The given day without its time, or the stored object when it is the same day.
     */
    private function keepDay(?\DateTimeImmutable $stored, ?\DateTimeImmutable $given): ?\DateTimeImmutable
    {
        if (!$given instanceof \DateTimeImmutable) {
            return null;
        }
        if ($stored instanceof \DateTimeImmutable && $stored->format('Y-m-d') === $given->format('Y-m-d')) {
            return $stored;
        }

        return new \DateTimeImmutable($given->format('Y-m-d'));
    }
}
