<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Domain;

use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Purchasing\Domain\ExchangeRate;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentContent;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Domain\ReceivedDocumentState;
use App\Purchasing\Domain\SupplierSnapshot;
use App\Purchasing\Domain\VatRate;
use App\Purchasing\Domain\VatRecapLine;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceivedDocumentTest extends TestCase
{
    private const string AT = '2026-11-10 10:00:00';

    public function testAnUploadedFileStartsAsADraft(): void
    {
        $document = $this->draft();
        $attachment = Id::generate();
        $document->attachOriginal($attachment);

        self::assertSame(ReceivedDocumentState::Draft, $document->state());
        self::assertNull($document->evidenceNumber());
        self::assertSame($attachment, $document->originalAttachmentId());
        self::assertSame('1', $document->exchangeRate()?->toString());
    }

    public function testRecordsACompleteDocument(): void
    {
        $document = $this->draft();
        $document->assignEvidenceNumber('R20260001');
        $document->record($this->content(), $user = Id::generate(), new \DateTimeImmutable(self::AT));

        self::assertTrue($document->isRecorded());
        self::assertSame($user, $document->recordedBy());
        self::assertSame(121_000, $document->total()?->minor());
        self::assertSame(100_000, $document->baseCzk()?->minor());
        self::assertSame(21_000, $document->vatCzk()?->minor());
        self::assertSame('2026-11-01', $document->deductionPeriod()?->format('Y-m-d'));
        self::assertSame('2028-12-31', $document->latestDeductionDate()?->format('Y-m-d'));
    }

    public function testTheTaxPointIsTheIssueDateWhenTheDocumentNamesNone(): void
    {
        $document = $this->recorded($this->content(taxPoint: null));

        self::assertSame('2026-10-28', $document->taxPointDate()?->format('Y-m-d'));
    }

    public function testAForeignInvoiceIsSelfAssessedAtItsRate(): void
    {
        $document = $this->recorded($this->content(
            kind: ReceivedDocumentKind::ForeignInvoice,
            treatment: PurchaseTaxTreatment::NonEuService,
            currency: Currency::USD,
            total: 2_000,
            lines: [new VatRecapLine(VatRate::of('21'), Money::ofMinor(2_000, Currency::USD), Money::ofMinor(0, Currency::USD))],
            rate: ExchangeRate::of('23.456'),
        ));

        self::assertSame('23.456', $document->exchangeRate()?->toString());
        self::assertSame(9_852, $document->vatCzk()?->minor());
        self::assertSame('2026-10-01', $document->deductionPeriod()?->format('Y-m-d'));
        self::assertNull($document->latestDeductionDate());
    }

    public function testAnOtherDocumentNeedsNoRecap(): void
    {
        $document = $this->recorded($this->content(kind: ReceivedDocumentKind::Other, treatment: PurchaseTaxTreatment::NotVatDocument, lines: []));

        self::assertTrue($document->vatRecap()->isEmpty());
        self::assertNull($document->deductionPeriod());
    }

    public function testACreditNoteNamesItsOriginal(): void
    {
        $original = Id::generate();
        $document = $this->recorded($this->content(kind: ReceivedDocumentKind::CreditNote, original: $original));

        self::assertSame($original, $document->originalDocumentId());
    }

    /**
     * @param \Closure(self): ReceivedDocumentContent $content
     */
    #[DataProvider('incomplete')]
    public function testRefusesToRecordWhatIsIncomplete(\Closure $content, string $reason): void
    {
        $document = $this->draft();
        $document->assignEvidenceNumber('R20260001');
        $given = $content($this);

        $this->assertRefused($reason, static fn () => $document->record($given, null, new \DateTimeImmutable(self::AT)));
        self::assertSame(ReceivedDocumentState::Draft, $document->state());
        // A draft takes the same values.
        $document->saveDraft($given);
    }

    /**
     * @return iterable<string, array{\Closure(self): ReceivedDocumentContent, string}>
     */
    public static function incomplete(): iterable
    {
        yield 'no supplier' => [static fn (self $test): ReceivedDocumentContent => $test->content(supplier: false), ReceivedDocumentRefused::SUPPLIER_MISSING];
        yield 'no issue date' => [static fn (self $test): ReceivedDocumentContent => $test->content(issue: null), ReceivedDocumentRefused::ISSUE_DATE_MISSING];
        yield 'no received date' => [static fn (self $test): ReceivedDocumentContent => $test->content(received: null), ReceivedDocumentRefused::RECEIVED_DATE_MISSING];
        yield 'no total' => [static fn (self $test): ReceivedDocumentContent => $test->content(total: null), ReceivedDocumentRefused::TOTAL_MISSING];
        yield 'no recap' => [static fn (self $test): ReceivedDocumentContent => $test->content(lines: []), ReceivedDocumentRefused::VAT_RECAP_MISSING];
        yield 'a credit note without its original' => [static fn (self $test): ReceivedDocumentContent => $test->content(kind: ReceivedDocumentKind::CreditNote), ReceivedDocumentRefused::ORIGINAL_MISSING];
        yield 'a foreign currency without a rate' => [static fn (self $test): ReceivedDocumentContent => $test->content(
            treatment: PurchaseTaxTreatment::EuService,
            currency: Currency::EUR,
            total: 10_000,
            lines: [new VatRecapLine(VatRate::of('21'), Money::ofMinor(10_000, Currency::EUR), Money::ofMinor(0, Currency::EUR))],
        ), ReceivedDocumentRefused::EXCHANGE_RATE_MISSING];
    }

    /**
     * @param \Closure(self): ReceivedDocumentContent $content
     */
    #[DataProvider('unfit')]
    public function testRefusesWhatNeverFitsEvenInADraft(\Closure $content, string $reason): void
    {
        $document = $this->draft();
        $given = $content($this);

        $this->assertRefused($reason, static fn () => $document->saveDraft($given));
        $this->assertRefused($reason, static fn (): ReceivedDocument => new ReceivedDocument($document->firm(), $given, null, new \DateTimeImmutable(self::AT)));
    }

    /**
     * @return iterable<string, array{\Closure(self): ReceivedDocumentContent, string}>
     */
    public static function unfit(): iterable
    {
        yield 'a simplified receipt in reverse charge' => [static fn (self $test): ReceivedDocumentContent => $test->content(kind: ReceivedDocumentKind::SimplifiedReceipt, treatment: PurchaseTaxTreatment::DomesticReverseCharge), ReceivedDocumentRefused::SIMPLIFIED_SELF_ASSESSED];
        yield 'a simplified receipt self-assessed' => [static fn (self $test): ReceivedDocumentContent => $test->content(kind: ReceivedDocumentKind::SimplifiedReceipt, treatment: PurchaseTaxTreatment::EuService), ReceivedDocumentRefused::SIMPLIFIED_SELF_ASSESSED];
        yield 'CZK at another rate' => [static fn (self $test): ReceivedDocumentContent => $test->content(rate: ExchangeRate::of('25')), ReceivedDocumentRefused::CZK_RATE_NOT_ONE];
        yield 'an original on an invoice' => [static fn (self $test): ReceivedDocumentContent => $test->content(original: Id::generate()), ReceivedDocumentRefused::ORIGINAL_NOT_CREDIT_NOTE];
        yield 'an original that is no id' => [static fn (self $test): ReceivedDocumentContent => $test->content(kind: ReceivedDocumentKind::CreditNote, original: 'nope'), ReceivedDocumentRefused::ORIGINAL_INVALID];
        yield 'VAT on an advance request' => [static fn (self $test): ReceivedDocumentContent => $test->content(kind: ReceivedDocumentKind::AdvanceRequest), ReceivedDocumentRefused::TREATMENT_FOR_KIND];
        yield 'a total in another currency' => [static fn (self $test): ReceivedDocumentContent => $test->content(currency: Currency::EUR, lines: [], rate: ExchangeRate::of('25'), totalCurrency: Currency::CZK), ReceivedDocumentRefused::CURRENCY_MISMATCH];
        yield 'a variable symbol with letters' => [static fn (self $test): ReceivedDocumentContent => $test->content(variableSymbol: 'INV-1'), ReceivedDocumentRefused::VARIABLE_SYMBOL];
        yield 'a long supplier number' => [static fn (self $test): ReceivedDocumentContent => $test->content(supplierNumber: str_repeat('1', ReceivedDocument::MAX_SUPPLIER_NUMBER_LENGTH + 1)), ReceivedDocumentRefused::SUPPLIER_NUMBER_TOO_LONG];
    }

    public function testACreditNoteIsNotItsOwnOriginal(): void
    {
        $document = $this->draft();

        $this->assertRefused(ReceivedDocumentRefused::ORIGINAL_INVALID, fn () => $document->saveDraft($this->content(kind: ReceivedDocumentKind::CreditNote, original: $document->id())));
    }

    public function testRecordingNeedsAnEvidenceNumber(): void
    {
        $document = $this->draft();

        $this->assertRefused(ReceivedDocumentRefused::EVIDENCE_NUMBER_MISSING, fn () => $document->record($this->content(), null, new \DateTimeImmutable(self::AT)));
    }

    public function testTheEvidenceNumberIsGivenOnce(): void
    {
        $document = $this->recorded($this->content());

        $this->assertRefused(ReceivedDocumentRefused::EVIDENCE_NUMBER_FIXED, static fn () => $document->assignEvidenceNumber('R20260002'));
        $this->assertRefused(ReceivedDocumentRefused::EVIDENCE_NUMBER_INVALID, fn () => $this->draft()->assignEvidenceNumber('  '));
    }

    public function testARecordedDocumentStaysRecorded(): void
    {
        $document = $this->recorded($this->content());
        $version = $document->version();

        $this->assertRefused(ReceivedDocumentRefused::RECORDED, fn () => $document->saveDraft($this->content()));
        $document->record($this->content(total: 130_000), null, new \DateTimeImmutable(self::AT));

        self::assertSame(130_000, $document->total()?->minor());
        self::assertSame('R20260001', $document->evidenceNumber());
        self::assertGreaterThan($version, $document->version());
    }

    public function testARecordedDocumentKeepsItsOriginalFile(): void
    {
        $document = $this->draft();
        $document->attachOriginal(Id::generate());
        $document->attachOriginal($file = Id::generate());
        $document->assignEvidenceNumber('R20260001');
        $document->record($this->content(), null, new \DateTimeImmutable(self::AT));
        $document->attachOriginal($file);

        $this->assertRefused(ReceivedDocumentRefused::ORIGINAL_FILE_FIXED, static fn () => $document->attachOriginal(Id::generate()));
        $this->assertRefused(ReceivedDocumentRefused::ORIGINAL_FILE_INVALID, static fn () => $document->attachOriginal('nope'));
    }

    public function testTheTrashKeepsTheDocumentUntilItIsRestored(): void
    {
        $document = $this->recorded($this->content());
        $user = Id::generate();

        $this->assertRefused(ReceivedDocumentRefused::REASON_MISSING, static fn () => $document->trash(new \DateTimeImmutable(self::AT), $user, ' '));
        $this->assertRefused(ReceivedDocumentRefused::REASON_TOO_LONG, static fn () => $document->trash(new \DateTimeImmutable(self::AT), $user, str_repeat('x', ReceivedDocument::MAX_REASON_LENGTH + 1)));
        $document->trash(new \DateTimeImmutable(self::AT), $user, 'Uploaded twice');

        self::assertTrue($document->isTrashed());
        self::assertSame('Uploaded twice', $document->deletedReason());
        $this->assertRefused(ReceivedDocumentRefused::TRASHED, fn () => $document->record($this->content(), null, new \DateTimeImmutable(self::AT)));
        $this->assertRefused(ReceivedDocumentRefused::TRASHED, static fn () => $document->trash(new \DateTimeImmutable(self::AT), $user, 'Again'));

        $document->restore();

        self::assertFalse($document->isTrashed());
        self::assertNull($document->deletedBy());
        self::assertSame('R20260001', $document->evidenceNumber());
    }

    public function testTheSameDayKeepsItsObject(): void
    {
        $document = $this->recorded($this->content());
        $issued = $document->issueDate();
        $document->record($this->content(issue: new \DateTimeImmutable('2026-10-28 15:30')), null, new \DateTimeImmutable(self::AT));

        self::assertSame($issued, $document->issueDate());
    }

    public function testADomesticReverseChargeMayCarryTheCodeOfItsSupply(): void
    {
        $lines = [new VatRecapLine(VatRate::of('21'), Money::ofMinor(100_000, Currency::CZK), Money::ofMinor(0, Currency::CZK))];
        $document = $this->recorded($this->content(treatment: PurchaseTaxTreatment::DomesticReverseCharge, total: 100_000, lines: $lines, supplyCode: ' 4 '));

        self::assertSame('4', $document->supplyCode());

        $document->record($this->content(treatment: PurchaseTaxTreatment::DomesticReverseCharge, total: 100_000, lines: $lines, supplyCode: ''), null, new \DateTimeImmutable(self::AT));
        self::assertNull($document->supplyCode(), 'The code is optional.');
        $this->assertRefused(ReceivedDocumentRefused::SUPPLY_CODE_INVALID, fn () => $document->record($this->content(treatment: PurchaseTaxTreatment::DomesticReverseCharge, total: 100_000, lines: $lines, supplyCode: '4/a'), null, new \DateTimeImmutable(self::AT)));
    }

    public function testOnlyADomesticReverseChargeTakesASupplyCode(): void
    {
        $this->assertRefused(ReceivedDocumentRefused::SUPPLY_CODE_NOT_ALLOWED, fn (): ReceivedDocument => $this->recorded($this->content(supplyCode: '4')));
    }

    public function testEveryChangeRaisesTheVersionByOne(): void
    {
        $document = $this->draft();
        self::assertSame(1, $document->version());

        $document->saveDraft($this->content());
        self::assertSame(2, $document->version());

        $document->attachOriginal(Id::generate());
        self::assertSame(3, $document->version());

        $document->assignEvidenceNumber('R20260001');
        $document->record($this->content(), null, new \DateTimeImmutable(self::AT));
        self::assertSame(4, $document->version());

        $document->trash(new \DateTimeImmutable(self::AT), null, 'Uploaded twice');
        self::assertSame(5, $document->version());

        $document->restore();
        self::assertSame(6, $document->version());
    }

    public function testATrashedDraftTakesNoChange(): void
    {
        $document = $this->draft();
        $document->trash(new \DateTimeImmutable(self::AT), null, 'Uploaded twice');

        $this->assertRefused(ReceivedDocumentRefused::TRASHED, fn () => $document->saveDraft($this->content()));
        $this->assertRefused(ReceivedDocumentRefused::TRASHED, static fn () => $document->assignEvidenceNumber('R20260001'));
        $this->assertRefused(ReceivedDocumentRefused::TRASHED, static fn () => $document->attachOriginal(Id::generate()));
        $this->assertRefused(ReceivedDocumentRefused::TRASHED, fn () => $document->checkRecordable($this->content()));
    }

    public function testCheckRecordableRefusesWhatNeverFits(): void
    {
        $this->assertRefused(ReceivedDocumentRefused::VARIABLE_SYMBOL, fn () => $this->draft()->checkRecordable($this->content(variableSymbol: 'INV-1')));
        $this->assertRefused(ReceivedDocumentRefused::TOTAL_MISSING, fn () => $this->draft()->checkRecordable($this->content(total: null)));
    }

    public function testTheEvidenceNumberMayBeAsLongAsTheLimit(): void
    {
        $document = $this->draft();
        $document->assignEvidenceNumber(str_repeat('1', ReceivedDocument::MAX_EVIDENCE_NUMBER_LENGTH));

        self::assertSame(ReceivedDocument::MAX_EVIDENCE_NUMBER_LENGTH, \strlen((string) $document->evidenceNumber()));
        $this->assertRefused(ReceivedDocumentRefused::EVIDENCE_NUMBER_INVALID, fn () => $this->draft()->assignEvidenceNumber(str_repeat('1', ReceivedDocument::MAX_EVIDENCE_NUMBER_LENGTH + 1)));
    }

    public function testTheReasonOfTheTrashCountsCharactersNotBytes(): void
    {
        $document = $this->draft();
        $reason = str_repeat('é', ReceivedDocument::MAX_REASON_LENGTH);

        $document->trash(new \DateTimeImmutable(self::AT), null, $reason);

        self::assertSame($reason, $document->deletedReason());
    }

    public function testTheSupplierNumberAndTheVariableSymbolAreTrimmed(): void
    {
        $number = str_repeat('é', ReceivedDocument::MAX_SUPPLIER_NUMBER_LENGTH);
        $document = $this->draft();
        $document->saveDraft($this->content(variableSymbol: ' 2026123 ', supplierNumber: '  '.$number.' '));

        self::assertSame($number, $document->supplierNumber());
        self::assertSame('2026123', $document->variableSymbol());
    }

    public function testTheTaxPointTheDocumentNamesDecidesTheDeadline(): void
    {
        $document = $this->recorded($this->content(
            issue: new \DateTimeImmutable('2026-01-05'),
            taxPoint: new \DateTimeImmutable('2025-12-20'),
            received: new \DateTimeImmutable('2026-01-10'),
        ));

        self::assertSame('2025-12-20', $document->taxPointDate()?->format('Y-m-d'));
        self::assertSame('2026-01-01', $document->deductionPeriod()?->format('Y-m-d'));
        self::assertSame('2027-12-31', $document->latestDeductionDate()?->format('Y-m-d'));
    }

    public function testADraftWithoutATaxPointUsesTheIssueDateForTheDeduction(): void
    {
        $document = $this->draft();
        $document->saveDraft($this->content(taxPoint: null));

        self::assertNull($document->taxPointDate());
        self::assertSame('2026-11-01', $document->deductionPeriod()?->format('Y-m-d'));
        self::assertSame('2028-12-31', $document->latestDeductionDate()?->format('Y-m-d'));
    }

    public function testTheCreatorMayBeUnknown(): void
    {
        $column = new \ReflectionProperty(ReceivedDocument::class, 'createdBy')->getAttributes(ORM\Column::class);

        self::assertCount(1, $column);
        self::assertTrue($column[0]->newInstance()->nullable);
    }

    public function testTheTenantIsKnownOnlyOncePersisted(): void
    {
        $this->expectException(\LogicException::class);

        $this->draft()->tenantId();
    }

    /**
     * @param list<VatRecapLine>|null $lines
     */
    public function content(
        ReceivedDocumentKind $kind = ReceivedDocumentKind::TaxInvoice,
        PurchaseTaxTreatment $treatment = PurchaseTaxTreatment::Domestic,
        bool $supplier = true,
        ?\DateTimeImmutable $issue = new \DateTimeImmutable('2026-10-28'),
        ?\DateTimeImmutable $taxPoint = new \DateTimeImmutable('2026-10-28'),
        ?\DateTimeImmutable $received = new \DateTimeImmutable('2026-11-02'),
        Currency $currency = Currency::CZK,
        ?int $total = 121_000,
        ?array $lines = null,
        ?ExchangeRate $rate = null,
        ?string $original = null,
        string $variableSymbol = '2026123',
        string $supplierNumber = 'FV-2026-123',
        ?Currency $totalCurrency = null,
        ?string $supplyCode = null,
    ): ReceivedDocumentContent {
        $party = new Party(PartyKind::Company, 'Supplier Example s.r.o.', new RegistrationNumber('CZ', '87654321'), null, '');

        return new ReceivedDocumentContent(
            $kind,
            $treatment,
            $supplier ? $party : null,
            $supplier ? SupplierSnapshot::of($party, new Address('Example Street 2', 'Example City', '110 00', 'CZ')) : SupplierSnapshot::none(),
            $supplierNumber,
            $variableSymbol,
            $issue,
            $taxPoint,
            null,
            $received,
            $currency,
            null === $total ? null : Money::ofMinor($total, $totalCurrency ?? $currency),
            $rate,
            null,
            $lines ?? [new VatRecapLine(VatRate::of('21'), Money::ofMinor(100_000, Currency::CZK), Money::ofMinor(21_000, Currency::CZK))],
            $original,
            $supplyCode,
        );
    }

    private function draft(): ReceivedDocument
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345678'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');

        return new ReceivedDocument($firm, ReceivedDocumentContent::blank(new \DateTimeImmutable('2026-11-02')), Id::generate(), new \DateTimeImmutable(self::AT));
    }

    private function recorded(ReceivedDocumentContent $content): ReceivedDocument
    {
        $document = $this->draft();
        $document->assignEvidenceNumber('R20260001');
        $document->record($content, null, new \DateTimeImmutable(self::AT));

        return $document;
    }

    private function assertRefused(string $reason, \Closure $change): void
    {
        try {
            $change();
            self::fail('The change was taken.');
        } catch (ReceivedDocumentRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
