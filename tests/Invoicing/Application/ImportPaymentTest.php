<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\DeductibleAdvances;
use App\Invoicing\Application\ImportedDocumentInput;
use App\Invoicing\Application\ImportedDocumentResult;
use App\Invoicing\Application\ImportedPaymentInput;
use App\Invoicing\Application\ImportedTotals;
use App\Invoicing\Application\ImportIssuedDocument;
use App\Invoicing\Application\ImportOutcome;
use App\Invoicing\Application\ImportPayment;
use App\Invoicing\Application\IssuePaymentTaxDocument;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\PaymentRefused;
use App\Invoicing\Application\UndoBlocker;
use App\Invoicing\Application\UndoImportedDocuments;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * Payments, tax documents on payment and invoices that deduct advances, as another
 * system recorded them, imported after the advances they follow.
 */
final class ImportPaymentTest extends AdvanceTestCase
{
    private const string RUN = '0192f0a0-0000-7000-8000-000000000011';

    private ImportIssuedDocument $documents;
    private ImportPayment $import;
    private Series $advances;
    private Series $taxDocuments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->documents = self::getContainer()->get(ImportIssuedDocument::class);
        $this->import = self::getContainer()->get(ImportPayment::class);
        $this->advances = new Series($this->firm, 'Advances', SeriesFormat::fromString('Z{YYYY}{NNNN}'), DocumentKind::Advance);
        $this->taxDocuments = new Series($this->firm, 'Tax documents', SeriesFormat::fromString('D{YYYY}{NNNN}'), DocumentKind::PaymentTaxDocument);
        $this->em->persist($this->advances);
        $this->em->persist($this->taxDocuments);
        $this->em->flush();
    }

    public function testAPaymentIsImportedOnItsDayWithoutATaxDocument(): void
    {
        $advance = $this->importedAdvance();

        $result = $this->import->import($this->payment($advance));

        self::assertSame(ImportOutcome::Imported, $result->outcome);
        $payment = $this->paymentOf($result->paymentId);
        self::assertSame('2026-10-05', $payment->receivedOn()->format('Y-m-d'));
        self::assertSame(12100, $payment->amount()->minor());
        self::assertSame(PaymentMethod::BankTransfer, $payment->method());
        self::assertSame('idoklad', $payment->importSource());
        self::assertSame('IssuedDocumentPayments/1', $payment->importExternalId());
        self::assertSame(self::RUN, $payment->importRunId());
        self::assertSame('2026-11-20 10:00:00', $payment->importedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(12100, $this->payments->paidOf($this->store->get($advance->id()))->minor());
        self::assertSame([], $this->taxDocumentsOf($advance), 'The source\'s tax document is imported on its own.');

        $again = $this->import->import($this->payment($advance, ['amount' => $this->czk('1.00')]));
        self::assertSame(ImportOutcome::AlreadyImported, $again->outcome);
        self::assertSame($result->paymentId, $again->paymentId);
        self::assertSame(1, $this->rowsOf(Payment::class));
    }

    public function testAPaymentIsImportedOnce(): void
    {
        $payment = $this->paymentOf($this->import->import($this->payment($this->importedAdvance()))->paymentId);

        $this->expectException(\DomainException::class);
        $payment->importedFrom('idoklad', 'IssuedDocumentPayments/2', self::RUN, new \DateTimeImmutable());
    }

    public function testWhatRecordPaymentRefusesIsRefused(): void
    {
        $advance = $this->importedAdvance();
        $trashed = $this->issue->issue($this->input(overrides: ['variableSymbol' => '20260002']));
        $this->trash->trash($trashed->id(), 'Wrong customer');
        $tax = $this->taxDocumentOf($advance, $this->paymentOf($this->import->import($this->payment($advance))->paymentId));
        $other = $this->firm(registrationNumber: '27074358');
        $this->em->flush();
        $cases = [
            PaymentRefused::NOT_POSITIVE => $this->payment($advance, ['amount' => $this->czk('0.00')]),
            PaymentRefused::NOTE_TOO_LONG => $this->payment($advance, ['note' => str_repeat('n', Payment::MAX_NOTE + 1)]),
            PaymentRefused::WRONG_FIRM => $this->payment($advance, ['firmId' => $other->id()]),
            PaymentRefused::TRASHED => $this->payment($trashed),
            PaymentRefused::NOT_PAYABLE => $this->payment($tax),
            PaymentRefused::CURRENCY_MISMATCH => $this->payment($advance, ['amount' => Money::of('10.00', Currency::EUR)]),
            PaymentRefused::FUTURE_DATE => $this->payment($advance, ['receivedOn' => new \DateTimeImmutable('2026-11-21')]),
        ];

        foreach ($cases as $reason => $input) {
            $result = $this->import->import(new \ReflectionClass(ImportedPaymentInput::class)->newInstanceArgs([...(array) $input, 'externalId' => 'refused/'.$reason]));

            self::assertSame(ImportOutcome::Refused, $result->outcome, $reason);
            self::assertSame($reason, $result->reason);
            self::assertNull($result->paymentId);
        }
        self::assertSame(1, $this->rowsOf(Payment::class));
        self::assertSame(ImportOutcome::Imported, $this->import->import($this->payment($advance, ['receivedOn' => new \DateTimeImmutable('2026-11-20'), 'externalId' => 'today']))->outcome, 'Today in the firm\'s country is no future.');
        $longest = $this->payment($this->issue->issue($this->input(overrides: ['variableSymbol' => '20260003'])), ['note' => str_repeat('ň', Payment::MAX_NOTE), 'externalId' => 'longest note']);
        self::assertSame(ImportOutcome::Imported, $this->import->import($longest)->outcome, 'A note is counted in characters, up to the limit.');
    }

    public function testATaxDocumentIsLinkedToItsPaymentAndAdvanceWithItsNumber(): void
    {
        $advance = $this->importedAdvance();
        $payment = $this->paymentOf($this->import->import($this->payment($advance))->paymentId);

        $tax = $this->taxDocumentOf($advance, $payment);

        self::assertSame(DocumentKind::PaymentTaxDocument, $tax->kind());
        self::assertSame('D20260001', $tax->number());
        self::assertSame($advance->id(), $tax->advanceInvoiceId());
        self::assertSame($payment->id(), $tax->paymentId());
        self::assertSame('2026-10-05', $tax->taxPointDate()?->format('Y-m-d'));
        self::assertSame([10000, 2100, 12100], [$tax->net()->minor(), $tax->vat()->minor(), $tax->gross()->minor()]);
        self::assertSame(self::RUN, $tax->importRunId());

        $again = $this->documents->import($this->taxDocument($advance, $payment, ['externalId' => 'IssuedTaxDocuments/2'], '20260002'));
        self::assertSame(['invoice.problem.tax_document_exists'], $this->keys($again), 'A payment has one tax document.');
    }

    public function testATaxDocumentNeedsALivePaymentOfItsAdvance(): void
    {
        $advance = $this->importedAdvance();
        $invoice = $this->stored($this->documents->import($this->document(DocumentKind::Invoice, $this->series, '20260001', [$this->gross('121.00')], ['taxPointDate' => new \DateTimeImmutable('2026-10-01')], new ImportedTotals(10000, 2100, 12100, 12100), 'IssuedInvoices/1')));
        $payment = $this->paymentOf($this->import->import($this->payment($invoice))->paymentId);

        self::assertSame(['invoice.problem.payment_unknown'], $this->keys($this->documents->import($this->taxDocument($advance, $payment))));
        self::assertSame(['invoice.problem.payment_unknown'], $this->keys($this->documents->import($this->taxDocument($invoice, $payment))), 'Only an advance is taxed on payment.');
        self::assertSame(['invoice.problem.import_kind_unsupported'], $this->keys($this->documents->import($this->taxDocument($advance, $payment, ['taxedPaymentId' => null]))));
        $paid = $this->paymentOf($this->import->import($this->payment($advance, ['externalId' => 'IssuedDocumentPayments/2']))->paymentId);
        self::assertSame(['invoice.problem.tax_document_with_vat'], $this->keys($this->documents->import($this->taxDocument($advance, $paid, lines: [$this->line('100.00')]))), 'Its deduction repeats its lines priced with VAT.');
    }

    public function testAnInvoiceDeductsAnImportedTaxDocumentAndSettlesTheAdvance(): void
    {
        $advance = $this->importedAdvance();
        $tax = $this->taxDocumentOf($advance, $this->paymentOf($this->import->import($this->payment($advance))->paymentId));
        $deductible = self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), null, DocumentLanguage::Czech);
        self::assertSame([$tax->id()], array_map(static fn ($offered): string => $offered->id, $deductible), 'The tax document of an open advance is deductible.');

        $result = $this->documents->import($this->finalInvoice($deductible[0]->lines));

        $invoice = $this->stored($result);
        self::assertSame([10000, 2100, 12100, 12100], [$invoice->net()->minor(), $invoice->vat()->minor(), $invoice->gross()->minor(), $invoice->payable()->minor()]);
        self::assertTrue($invoice->deductsAdvances());
        $this->em->clear();
        self::assertSame($invoice->id(), $this->store->get($tax->id())->deductedByInvoiceId());
        self::assertSame([], self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), null, DocumentLanguage::Czech));

        $twice = $this->documents->import($this->finalInvoice($deductible[0]->lines, '20260002'));
        self::assertSame(['invoice.problem.deduction_invalid'], $this->keys($twice), 'The rules of issuing hold: what is deducted is offered no more.');
    }

    public function testARunIsTakenBackWithItsPaymentsAndTaxDocuments(): void
    {
        $other = ['runId' => '0192f0a0-0000-7000-8000-000000000012'];
        $advance = $this->importedAdvance();
        $this->taxDocumentOf($advance, $this->paymentOf($this->import->import($this->payment($advance))->paymentId));
        $kept = $this->importedAdvance('Z20260002', 'ProformaInvoices/2', $other);
        $keptPayment = $this->paymentOf($this->import->import($this->payment($kept, ['externalId' => 'IssuedDocumentPayments/2', ...$other]))->paymentId);
        $keptTax = $this->stored($this->documents->import($this->taxDocument($kept, $keptPayment, ['externalId' => 'IssuedTaxDocuments/2', ...$other], '20260002')));
        $deductible = self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), null, DocumentLanguage::Czech);
        $lines = array_merge(...array_map(static fn ($offered): array => $offered->lines, $deductible));
        $this->stored($this->documents->import($this->document(DocumentKind::Invoice, $this->series, '20260001', [$this->line('300.00'), ...$lines], ['taxPointDate' => new \DateTimeImmutable('2026-10-20'), 'issueDate' => new \DateTimeImmutable('2026-10-20')], new ImportedTotals(10000, 2100, 12100, 12100), 'IssuedInvoices/1')));

        self::assertSame([], self::getContainer()->get(UndoImportedDocuments::class)->undo(self::RUN), 'What the run deducted, taxed and paid is its own.');

        $this->em->clear();
        self::assertEqualsCanonicalizing([$kept->id(), $keptTax->id()], $this->em->createQuery('SELECT i.id FROM '.Invoice::class.' i')->getSingleColumnResult(), 'Another run stays.');
        self::assertSame([$keptPayment->id()], $this->em->createQuery('SELECT p.id FROM '.Payment::class.' p')->getSingleColumnResult());
        self::assertSame([$keptPayment->id()], $this->em->getConnection()->fetchFirstColumn('SELECT payment_id FROM payment_allocation'));
        self::assertNull($this->store->get($keptTax->id())->deductedByInvoiceId(), 'What the run deducted of another is deductible again.');
    }

    public function testAPaymentCancelledOrTaxedHereKeepsTheRun(): void
    {
        $cancelled = $this->importedAdvance();
        $cancelledPayment = $this->import->import($this->payment($cancelled))->paymentId;
        $taxed = $this->importedAdvance('Z20260002', 'ProformaInvoices/2');
        $taxedPayment = $this->import->import($this->payment($taxed, ['externalId' => 'IssuedDocumentPayments/2']))->paymentId;
        $this->cancel->cancel((string) $cancelledPayment);
        $byHand = self::getContainer()->get(IssuePaymentTaxDocument::class)->byHand($taxed->id(), (string) $taxedPayment);

        $blockers = self::getContainer()->get(UndoImportedDocuments::class)->undo(self::RUN);

        self::assertEquals([
            new UndoBlocker($taxed->id(), 'Z20260002', UndoBlocker::TAX_DOCUMENTS),
            UndoBlocker::ofPayment((string) $cancelledPayment, UndoBlocker::CANCELLED),
            UndoBlocker::ofPayment((string) $taxedPayment, UndoBlocker::TAX_DOCUMENTS),
        ], $blockers);
        self::assertSame(3, $this->rowsOf(Invoice::class));
        self::assertNotNull($this->store->get($byHand->id())->paymentId());
    }

    public function testAnImportedPaymentIsKeptAfterThirtyDays(): void
    {
        $payment = $this->import->import($this->payment($this->issue->issue($this->input())))->paymentId;
        \Symfony\Component\Clock\Clock::set(new \Symfony\Component\Clock\MockClock('2026-12-20 10:00:01', 'UTC'));

        self::assertEquals([UndoBlocker::ofPayment((string) $payment, UndoBlocker::EXPIRED)], self::getContainer()->get(UndoImportedDocuments::class)->undo(self::RUN));
        \Symfony\Component\Clock\Clock::set(new \Symfony\Component\Clock\MockClock('2026-12-20 10:00:00', 'UTC'));
        self::assertSame([], self::getContainer()->get(UndoImportedDocuments::class)->undo(self::RUN), 'The thirtieth day is still within.');
    }

    /**
     * @param array<string, mixed> $overrides named arguments of ImportedDocumentInput
     */
    private function importedAdvance(string $number = 'Z20260001', string $externalId = 'ProformaInvoices/1', array $overrides = []): Invoice
    {
        return $this->stored($this->documents->import($this->document(DocumentKind::Advance, $this->advances, $number, [$this->gross('121.00')], ['taxPointDate' => null], new ImportedTotals(10000, 2100, 12100, 12100), $externalId, $overrides)));
    }

    /**
     * @param array<string, mixed> $overrides named arguments of ImportedDocumentInput
     * @param list<LineInput>|null $lines
     */
    private function taxDocument(Invoice $advance, Payment $payment, array $overrides = [], string $serial = '20260001', ?array $lines = null): ImportedDocumentInput
    {
        return $this->document(DocumentKind::PaymentTaxDocument, $this->taxDocuments, 'D'.$serial, $lines ?? [$this->gross('121.00')], ['taxPointDate' => new \DateTimeImmutable('2026-10-05'), 'issueDate' => new \DateTimeImmutable('2026-10-05'), 'dueDate' => new \DateTimeImmutable('2026-10-05')], new ImportedTotals(10000, 2100, 12100, 12100), 'IssuedTaxDocuments/1', [
            'taxedAdvanceId' => $advance->id(),
            'taxedPaymentId' => $payment->id(),
            ...$overrides,
        ]);
    }

    private function taxDocumentOf(Invoice $advance, Payment $payment): Invoice
    {
        return $this->stored($this->documents->import($this->taxDocument($advance, $payment)));
    }

    /**
     * Two hours at 10 000 CZK without VAT, less the deduction.
     *
     * @param list<LineInput> $deductions
     */
    private function finalInvoice(array $deductions, string $number = '20260001'): ImportedDocumentInput
    {
        return $this->document(DocumentKind::Invoice, $this->series, $number, [$this->line('200.00'), ...$deductions], ['taxPointDate' => new \DateTimeImmutable('2026-10-20'), 'issueDate' => new \DateTimeImmutable('2026-10-20'), 'dueDate' => new \DateTimeImmutable('2026-11-03')], new ImportedTotals(10000, 2100, 12100, 12100), 'IssuedInvoices/'.$number);
    }

    /**
     * @param list<LineInput>      $lines
     * @param array<string, mixed> $document  named arguments of InvoiceInput
     * @param array<string, mixed> $overrides named arguments of ImportedDocumentInput
     */
    private function document(DocumentKind $kind, Series $series, string $number, array $lines, array $document, ImportedTotals $expected, string $externalId, array $overrides = []): ImportedDocumentInput
    {
        $customer = new DocumentParty('Example Customer a.s.', '87654320', 'CZ87654320', new Address('Customer Street 2', 'Customer City', '200 00', 'CZ'), '', 'billing@example.com', '', '', null, null);

        return new \ReflectionClass(ImportedDocumentInput::class)->newInstanceArgs([
            'document' => $this->input($lines, [
                'seriesId' => $series->id(),
                'typedNumber' => $number,
                'kind' => $kind,
                'customerOnDocument' => $customer,
                'issueDate' => new \DateTimeImmutable('2026-10-01'),
                'dueDate' => new \DateTimeImmutable('2026-10-15'),
                'variableSymbol' => preg_replace('/\D/', '', $number),
                ...$document,
            ]),
            'issuer' => new DocumentParty('Example Firm s.r.o.', '12345695', 'CZ12345695', new Address('Example Street 1', 'Example City', '100 00', 'CZ'), '', 'office@example.com', '', '', null, null),
            'regime' => VatRegime::Payer,
            'rounding' => TotalRounding::None,
            'createdAt' => new \DateTimeImmutable('2026-10-01 09:00:00'),
            'deliveredAt' => null,
            'exportedAt' => null,
            'expected' => $expected,
            'source' => 'idoklad',
            'externalId' => $externalId,
            'runId' => self::RUN,
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides named arguments of ImportedPaymentInput
     */
    private function payment(Invoice $invoice, array $overrides = []): ImportedPaymentInput
    {
        return new \ReflectionClass(ImportedPaymentInput::class)->newInstanceArgs([
            'firmId' => $this->firm->id(),
            'invoiceId' => $invoice->id(),
            'receivedOn' => new \DateTimeImmutable('2026-10-05'),
            'amount' => $this->czk('121.00'),
            'method' => PaymentMethod::BankTransfer,
            'note' => '',
            'source' => 'idoklad',
            'externalId' => 'IssuedDocumentPayments/1',
            'runId' => self::RUN,
            ...$overrides,
        ]);
    }

    private function paymentOf(?string $id): Payment
    {
        $this->em->clear();

        return $this->em->find(Payment::class, $id ?? self::fail('Expected an imported payment.')) ?? self::fail('The payment is stored.');
    }

    private function stored(ImportedDocumentResult $result): Invoice
    {
        return $this->store->get($result->invoiceId ?? self::fail('Expected a stored document, got '.$result->outcome->value.': '.implode(', ', $this->keys($result)).json_encode($result->computed)));
    }

    /**
     * @return list<string>
     */
    private function keys(ImportedDocumentResult $result): array
    {
        return array_map(static fn (IssueProblem $problem): string => $problem->key, $result->problems);
    }

    /**
     * @param class-string $entity
     */
    private function rowsOf(string $entity): int
    {
        $this->em->clear();

        return (int) $this->em->createQuery('SELECT COUNT(e.id) FROM '.$entity.' e')->getSingleScalarResult();
    }
}
