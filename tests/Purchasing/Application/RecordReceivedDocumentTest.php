<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application;

use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyFirmSettings;
use App\Party\Domain\PartyRole;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\ReceivedDocumentWarning;
use App\Purchasing\Application\SavedReceivedDocument;
use App\Purchasing\Application\UnknownReceivedDocument;
use App\Purchasing\Domain\EvidenceSeries;
use App\Purchasing\Domain\ExchangeRate;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Domain\ReceivedDocumentState;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;

final class RecordReceivedDocumentTest extends PurchasingTestCase
{
    public function testADraftIsRecordedWithTheFirstNumberOfASeriesMadeOnFirstUse(): void
    {
        $draft = $this->draft();
        self::assertSame([], $this->em->getRepository(EvidenceSeries::class)->findAll());

        $saved = $this->recording()->record($draft, $this->input());

        $series = $this->em->getRepository(EvidenceSeries::class)->findAll();
        self::assertCount(1, $series);
        self::assertSame('R{YYYY}{NNNN}', $series[0]->format()->toString());
        $document = $this->document($draft);
        self::assertSame(ReceivedDocumentState::Recorded, $document->state());
        self::assertSame('R20260001', $document->evidenceNumber());
        self::assertSame($this->owner->user()->id(), $document->recordedBy());
        self::assertNotNull($document->originalAttachmentId(), 'The uploaded file stays its original.');
        self::assertTrue($saved->warnings->isEmpty());

        $next = $this->recording()->record($this->draft('%PDF-1.4 another'), $this->input(supplierNumber: 'FV-2026-124'));
        self::assertSame('R20260002', $next->document->evidenceNumber());
        self::assertCount(1, $this->em->getRepository(EvidenceSeries::class)->findAll());
    }

    public function testTheNumberCountsInTheYearOfTheReceivedDate(): void
    {
        $saved = $this->recording()->create($this->firm->id(), $this->input(issue: '2025-12-20', taxPoint: '2025-12-20', received: '2025-12-30'));

        self::assertSame('R20250001', $saved->document->evidenceNumber());
    }

    public function testAnIncompleteDocumentTakesNoNumber(): void
    {
        $draft = $this->draft();

        $this->assertRefused(ReceivedDocumentRefused::SUPPLIER_MISSING, fn (): SavedReceivedDocument => $this->recording()->record($draft, $this->input(supplierId: null)));
        $this->assertRefused(ReceivedDocumentRefused::RECEIVED_DATE_MISSING, fn (): SavedReceivedDocument => $this->recording()->record($draft, $this->input(received: null)));

        self::assertSame(ReceivedDocumentState::Draft, $this->document($draft)->state());
        self::assertSame('R20260001', $this->recording()->record($draft, $this->input())->document->evidenceNumber(), 'Nothing was taken from the series.');
    }

    public function testTheSupplierIsFrozenOnTheDocument(): void
    {
        $document = $this->recording()->record($this->draft(), $this->input())->document;

        self::assertSame($this->supplier->id(), $document->supplierParty()?->id());
        self::assertSame('Supplier Example s.r.o.', $document->supplier()->name);
        self::assertSame('CZ87654321', $document->supplier()->vatId);
        self::assertSame('87654321', $document->supplier()->registrationNumber);
        self::assertSame('Supplier Street 2', $document->supplier()->address->street);
    }

    public function testTheSupplierBecomesASupplierOfTheOnlyFirm(): void
    {
        self::assertSame([], $this->supplier->roles());

        $this->recording()->record($this->draft(), $this->input());

        $this->em->clear();
        $supplier = $this->em->find(Party::class, $this->supplier->id());
        self::assertInstanceOf(Party::class, $supplier);
        self::assertSame([PartyRole::Supplier], $supplier->roles(), 'With one firm, the shared roles change.');
    }

    public function testWithSeveralFirmsTheSupplierBecomesASupplierOfThatFirmOnly(): void
    {
        $other = $this->firm('Other Firm s.r.o.', '25596641');
        $this->supplier->assignRoles([PartyRole::Customer]);
        $this->em->flush();

        $this->recording()->record($this->draft(), $this->input());

        $this->em->clear();
        $supplier = $this->em->find(Party::class, $this->supplier->id());
        self::assertInstanceOf(Party::class, $supplier);
        $contacts = self::getContainer()->get(ContactSettingsForFirm::class);
        self::assertSame([PartyRole::Customer, PartyRole::Supplier], $contacts->rolesFor($supplier, $this->firmOf($this->firm->id())));
        self::assertSame([PartyRole::Customer], $contacts->rolesFor($supplier, $this->firmOf($other->id())));
        self::assertCount(1, $this->em->getRepository(PartyFirmSettings::class)->findAll());
    }

    public function testARateIsTakenFromThePublishedRatesOfTheTaxPoint(): void
    {
        $document = $this->recording()->record($this->draft(), $this->input(kind: ReceivedDocumentKind::ForeignInvoice, treatment: PurchaseTaxTreatment::EuService, taxPoint: '2026-11-08', currency: Currency::EUR, base: 10_000))->document;

        self::assertSame('24.3', $document->exchangeRate()?->toString(), 'Sunday has the rate of Friday.');
        self::assertSame('2026-11-06', $document->exchangeRateDate()?->format('Y-m-d'));
        self::assertSame(243_000, $document->baseCzk()?->minor());
        self::assertSame(51_030, $document->vatCzk()?->minor(), 'Self-assessed at 21 % of the base in CZK.');
    }

    public function testATypedRateWins(): void
    {
        $document = $this->recording()->record($this->draft(), $this->input(kind: ReceivedDocumentKind::ForeignInvoice, treatment: PurchaseTaxTreatment::EuService, currency: Currency::EUR, base: 10_000, rate: ExchangeRate::of('25.1')))->document;

        self::assertSame('25.1', $document->exchangeRate()?->toString());
        self::assertSame([], $this->rates->asked, 'No rate was looked up.');
    }

    public function testADocumentWithoutAPublishedRateIsNotRecorded(): void
    {
        $draft = $this->draft();

        $this->assertRefused(ReceivedDocumentRefused::EXCHANGE_RATE_UNAVAILABLE, fn (): SavedReceivedDocument => $this->recording()->record($draft, $this->input(kind: ReceivedDocumentKind::ForeignInvoice, treatment: PurchaseTaxTreatment::NonEuService, currency: Currency::USD, base: 10_000)));
        self::assertSame(ReceivedDocumentState::Draft, $this->document($draft)->state());
    }

    public function testADomesticReverseChargeKeepsTheCodeOfItsSupply(): void
    {
        $document = $this->recording()->record($this->draft(), $this->input(treatment: PurchaseTaxTreatment::DomesticReverseCharge, supplyCode: '4'))->document;

        self::assertSame('4', $document->supplyCode());
    }

    public function testTheSameSupplierNumberWarnsOfTheDocumentRecordedWithIt(): void
    {
        $first = $this->recording()->record($this->draft(), $this->input(supplierNumber: 'FV-2026-123'))->document->id();

        $saved = $this->recording()->record($this->draft('%PDF-1.4 another'), $this->input(supplierNumber: 'fv-2026-123 '));

        self::assertTrue($saved->document->isRecorded(), 'A duplicate only warns.');
        self::assertSame([ReceivedDocumentWarning::DUPLICATE_SUPPLIER_NUMBER], $saved->warnings->codes());
        self::assertSame([$first], $saved->warnings->get(ReceivedDocumentWarning::DUPLICATE_SUPPLIER_NUMBER)?->documentIds);
    }

    public function testADeductionDeadlinePassedOrCloseWarns(): void
    {
        $passed = $this->recording()->create($this->firm->id(), $this->input(issue: '2023-06-01', taxPoint: '2023-06-01', received: '2026-11-02'));
        self::assertSame([ReceivedDocumentWarning::DEDUCTION_DEADLINE_PASSED], $passed->warnings->codes());
        self::assertSame('2025-12-31', $passed->warnings->get(ReceivedDocumentWarning::DEDUCTION_DEADLINE_PASSED)?->date?->format('Y-m-d'));

        $close = $this->recording()->create($this->firm->id(), $this->input(supplierNumber: 'FV-2024-9', issue: '2024-12-15', taxPoint: '2024-12-15', received: '2026-11-02'));
        self::assertSame([ReceivedDocumentWarning::DEDUCTION_DEADLINE_NEAR], $close->warnings->codes(), '31 December 2026 is 51 days away.');

        $later = $this->recording()->create($this->firm->id(), $this->input(supplierNumber: 'FV-2025-9', issue: '2025-01-15', taxPoint: '2025-01-15', received: '2026-11-02'));
        self::assertTrue($later->warnings->isEmpty());
    }

    public function testASimplifiedReceiptOverTheLimitWarns(): void
    {
        $over = $this->recording()->create($this->firm->id(), $this->input(kind: ReceivedDocumentKind::SimplifiedReceipt, base: 900_000));
        self::assertSame([ReceivedDocumentWarning::SIMPLIFIED_OVER_LIMIT], $over->warnings->codes(), '10,890 CZK with VAT.');

        $within = $this->recording()->create($this->firm->id(), $this->input(kind: ReceivedDocumentKind::SimplifiedReceipt, supplierNumber: 'P-2', base: 826_446));
        self::assertTrue($within->warnings->isEmpty(), '9,999.99 CZK with VAT.');
    }

    public function testADocumentIsCreatedAndRecordedAtOnce(): void
    {
        $saved = $this->recording()->create($this->firm->id(), $this->input());

        $document = $this->document($saved->document->id());
        self::assertTrue($document->isRecorded());
        self::assertSame('R20260001', $document->evidenceNumber());
        self::assertNull($document->originalAttachmentId());
    }

    public function testACreditNoteNamesARecordedDocumentOfTheFirm(): void
    {
        $original = $this->recording()->create($this->firm->id(), $this->input())->document->id();
        $draft = $this->draft();

        $note = $this->recording()->record($draft, $this->input(kind: ReceivedDocumentKind::CreditNote, supplierNumber: 'D-1', base: -10_000, originalDocumentId: $original))->document;
        self::assertSame($original, $note->originalDocumentId());

        $other = $this->firm('Other Firm s.r.o.', '25596641');
        $foreign = $this->recording()->create($other->id(), $this->input())->document->id();
        $this->assertRefused(ReceivedDocumentRefused::ORIGINAL_INVALID, fn (): SavedReceivedDocument => $this->recording()->create($this->firm->id(), $this->input(kind: ReceivedDocumentKind::CreditNote, supplierNumber: 'D-2', base: -10_000, originalDocumentId: $foreign)));
        $this->assertRefused(ReceivedDocumentRefused::ORIGINAL_INVALID, fn (): SavedReceivedDocument => $this->recording()->create($this->firm->id(), $this->input(kind: ReceivedDocumentKind::CreditNote, supplierNumber: 'D-3', base: -10_000, originalDocumentId: $this->draft('%PDF-1.4 draft'))));
    }

    public function testRefusalsOfTheUseCase(): void
    {
        $draft = $this->draft();

        $this->assertRefused(ReceivedDocumentRefused::SUPPLIER_UNKNOWN, fn (): SavedReceivedDocument => $this->recording()->record($draft, $this->input(supplierId: Id::generate())));
        $this->assertRefused(ReceivedDocumentRefused::STALE, fn (): SavedReceivedDocument => $this->recording()->record($draft, $this->input(), 99));
        $this->recording()->record($draft, $this->input());
        $this->assertRefused(ReceivedDocumentRefused::RECORDED, fn (): SavedReceivedDocument => $this->recording()->record($draft, $this->input()));
    }

    public function testAnUnknownDocumentIsNotFound(): void
    {
        $this->expectException(UnknownReceivedDocument::class);
        $this->recording()->record(Id::generate(), $this->input());
    }

    public function testAGuestIsRefused(): void
    {
        $draft = $this->draft();
        $this->signIn($this->guest);

        try {
            $this->recording()->record($draft, $this->input());
            self::fail('A guest recorded a document.');
        } catch (PurchasingNotAllowed) {
        }
        $this->expectException(PurchasingNotAllowed::class);
        $this->recording()->create($this->firm->id(), $this->input());
    }

    private function firmOf(string $id): LegalEntity
    {
        return $this->em->find(LegalEntity::class, $id) ?? throw new \LogicException('No such firm.');
    }
}
