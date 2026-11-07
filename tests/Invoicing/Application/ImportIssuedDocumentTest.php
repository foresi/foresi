<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Invoicing\Application\AdvanceDeductions;
use App\Invoicing\Application\AdvanceDocuments;
use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\CustomerCopies;
use App\Invoicing\Application\DocumentExports;
use App\Invoicing\Application\ImportedDocumentInput;
use App\Invoicing\Application\ImportedDocumentResult;
use App\Invoicing\Application\ImportedDocuments;
use App\Invoicing\Application\ImportedTotals;
use App\Invoicing\Application\ImportIssuedDocument;
use App\Invoicing\Application\ImportOutcome;
use App\Invoicing\Application\InvoiceBundle;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRules;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\LinePricing;
use App\Invoicing\Application\NumberAllocator;
use App\Invoicing\Application\OriginalDocuments;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Application\UndoBlocker;
use App\Invoicing\Application\UndoImportedDocuments;
use App\Invoicing\Application\UndrawableDocument;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\ExportState;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\LegalEntities;
use App\Party\Application\PartyQuery;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Tenancy\Application\TenantContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class ImportIssuedDocumentTest extends AdvanceTestCase
{
    private const string RUN = '0192f0a0-0000-7000-8000-000000000001';
    private const string PDF = "%PDF-1.4\n% the original from the source\n%%EOF\n";

    private ImportIssuedDocument $import;
    private UndoImportedDocuments $undo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->import = self::getContainer()->get(ImportIssuedDocument::class);
        $this->undo = self::getContainer()->get(UndoImportedDocuments::class);
    }

    public function testAMatchingDocumentIsStoredAsItWasIssuedThere(): void
    {
        $result = $this->import->import($this->imported());

        self::assertSame(ImportOutcome::Imported, $result->outcome);
        $invoice = $this->reloaded($this->stored($result));
        self::assertSame('20240007', $invoice->number());
        self::assertSame('Old Name s.r.o.', $invoice->issuer()->name);
        self::assertSame('Old Street 5', $invoice->issuer()->address->street);
        self::assertSame('', $invoice->issuer()->registerEntry);
        self::assertSame('office@example.com', $invoice->issuer()->email);
        self::assertSame('Customer As Printed a.s.', $invoice->customer()->name);
        self::assertSame('2024-03-01 09:30:00', $invoice->createdAt()->format('Y-m-d H:i:s'));
        self::assertSame('2024-03-02 08:00:00', $invoice->deliveredAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2024-04-05 10:00:00', $invoice->exportedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(ExportState::Exported, $invoice->exportState());
        self::assertSame(1, $invoice->version());
        self::assertSame('idoklad', $invoice->importSource());
        self::assertSame('4711', $invoice->importExternalId());
        self::assertSame(self::RUN, $invoice->importRunId());
        self::assertSame('2026-11-20 10:00:00', $invoice->importedAt()?->format('Y-m-d H:i:s'));
        self::assertSame([100000, 21000, 121000, 121000], [$invoice->net()->minor(), $invoice->vat()->minor(), $invoice->gross()->minor(), $invoice->payable()->minor()]);
        self::assertCount(1, $this->store->lines($invoice));
        self::assertNull($invoice->originalPdfKey());
        self::assertSame(['20240008'], [$this->counter->next($this->series, new \DateTimeImmutable('2024-05-01'))]);
        self::assertTrue(self::getContainer()->get(ContactSettingsForFirm::class)->isCustomer($this->customer, $this->firm));
    }

    public function testADocumentIsImportedOnce(): void
    {
        $invoice = $this->stored($this->import->import($this->imported()));

        $this->expectException(\DomainException::class);
        $invoice->importedFrom('idoklad', '4712', self::RUN, new \DateTimeImmutable(), null, null);
    }

    public function testADifferenceInAnyTotalStoresNothing(): void
    {
        $result = $this->import->import($this->imported(overrides: ['expected' => new ImportedTotals(100000, 21000, 121000, 121001)]));

        self::assertSame(ImportOutcome::Mismatch, $result->outcome);
        self::assertEquals(new ImportedTotals(100000, 21000, 121000, 121001), $result->expected);
        self::assertEquals(new ImportedTotals(100000, 21000, 121000, 121000), $result->computed);
        self::assertSame(0, $this->invoiceCount());
    }

    public function testTheSameDocumentIsImportedOnce(): void
    {
        $first = $this->import->import($this->imported(pdf: self::PDF));
        $again = $this->import->import($this->imported(overrides: ['expected' => new ImportedTotals(1, 1, 1, 1)]));

        self::assertSame(ImportOutcome::AlreadyImported, $again->outcome);
        self::assertSame($first->invoiceId, $again->invoiceId);
        self::assertSame(1, $this->invoiceCount());
    }

    public function testATakenNumberIsRefused(): void
    {
        $this->issue->issue($this->input());

        $result = $this->import->import($this->imported(document: ['typedNumber' => '20260001', 'issueDate' => new \DateTimeImmutable('2026-11-01'), 'taxPointDate' => new \DateTimeImmutable('2026-11-01')]));

        self::assertSame(ImportOutcome::Refused, $result->outcome);
        self::assertSame(['invoice.problem.number_taken'], $this->keys($result));
        self::assertSame(1, $this->invoiceCount());
    }

    public function testATaxDocumentOnPaymentOrACorrectionIsNotImportedHere(): void
    {
        foreach ([DocumentKind::PaymentTaxDocument, DocumentKind::CorrectionDocument] as $kind) {
            $result = $this->import->import($this->imported(document: ['kind' => $kind]));

            self::assertSame(['invoice.problem.import_kind_unsupported'], $this->keys($result));
        }
        $taxedCorrection = $this->imported(document: ['kind' => DocumentKind::CorrectionDocument], overrides: ['taxedAdvanceId' => '0192f0a0-0000-7000-8000-0000000000ab', 'taxedPaymentId' => '0192f0a0-0000-7000-8000-0000000000ac']);
        self::assertSame(['invoice.problem.import_kind_unsupported'], $this->keys($this->import->import($taxedCorrection)), 'Only a tax document on payment taxes a payment.');
        self::assertSame(0, $this->invoiceCount());
    }

    public function testWhatOnlyANewDocumentNeedsDoesNotHoldAnOldOne(): void
    {
        $this->series->archive();
        $this->em->flush();

        $result = $this->import->import($this->imported(document: ['bankAccount' => 'CZ5508000000001234567899']));

        self::assertSame(ImportOutcome::Imported, $result->outcome);
        self::assertSame('CZ5508000000001234567899', $this->stored($result)->bankAccount());
    }

    public function testHistoryIsKeptAsIssuedWithoutAnAddressOrTheIssuersVatId(): void
    {
        $nameOnly = new DocumentParty('Walk-in Customer', null, null, new Address('', ' ', '', 'CZ'), '', null, '', '', null, null);
        $noVatId = new DocumentParty('Old Name s.r.o.', '12345695', null, new Address('Old Street 5', 'Old City', '110 00', 'CZ'), '', null, '', '', null, null);

        $result = $this->import->import($this->imported(document: ['customerOnDocument' => $nameOnly], overrides: ['issuer' => $noVatId]));

        self::assertSame(ImportOutcome::Imported, $result->outcome, implode(', ', $this->keys($result)));
        self::assertSame('Walk-in Customer', $this->stored($result)->customer()->name);
        self::assertNull($this->stored($result)->issuer()->vatId);
    }

    public function testTheLegalInvariantsStillHold(): void
    {
        $noVatId = new DocumentParty('Old Name s.r.o.', '12345695', 'VAT-1', new Address('Old Street 5', 'Old City', '110 00', 'CZ'), '', null, '', '', null, null);
        $noStreet = new DocumentParty('Old Name s.r.o.', '12345695', 'CZ12345695', new Address('', 'Old City', '110 00', 'CZ'), '', null, '', '', null, null);
        $tooLong = new DocumentParty(str_repeat('a', DocumentParty::MAX_NAME + 1), '12345695', 'CZ12345695', new Address('Old Street 5', 'Old City', '110 00', 'CZ'), '', null, '', '', null, null);
        $unprintable = new DocumentParty("Old \u{1F600} s.r.o.", '12345695', 'CZ12345695', new Address('Old Street 5', 'Old City', '110 00', 'CZ'), '', null, '', '', null, null);
        $deduction = new LineInput('Advance', $this->line()->quantity, 'h', $this->line()->price, $this->line()->priceType, TaxTreatment::Standard, $this->line()->rateKind, null, $this->line()->discount, '0192f0a0-0000-7000-8000-0000000000aa');

        self::assertSame(['invoice.problem.tax_point_missing'], $this->keys($this->import->import($this->imported(document: ['taxPointDate' => null]))));
        self::assertSame(['invoice.problem.firm_vat_id_missing'], $this->keys($this->import->import($this->imported(overrides: ['issuer' => $noVatId]))), 'A VAT ID printed is one.');
        self::assertSame(['invoice.problem.customer_address_incomplete'], $this->keys($this->import->import($this->imported(document: ['customerOnDocument' => new DocumentParty(' ', null, null, new Address('', '', '', 'CZ'), '', null, '', '', null, null)]))), 'A customer copy has its name.');
        self::assertSame(['invoice.problem.customer_address_incomplete'], $this->keys($this->import->import($this->imported(document: ['customerOnDocument' => new DocumentParty('Walk-in Customer', null, null, new Address('', '', '', ' '), '', null, '', '', null, null)]))), 'And its country.');
        self::assertSame(['invoice.problem.import_issuer_incomplete'], $this->keys($this->import->import($this->imported(overrides: ['issuer' => $noStreet]))));
        self::assertSame(['invoice.problem.too_long'], $this->keys($this->import->import($this->imported(overrides: ['issuer' => $tooLong]))));
        self::assertSame(['invoice.problem.unprintable_part'], $this->keys($this->import->import($this->imported(overrides: ['issuer' => $unprintable]))));
        self::assertSame(['invoice.problem.import_number_missing'], $this->keys($this->import->import($this->imported(document: ['typedNumber' => null]))));
        self::assertSame(['invoice.problem.import_number_missing'], $this->keys($this->import->import($this->imported(document: ['seriesId' => null]))));
        self::assertSame(['invoice.problem.deduction_invalid'], $this->keys($this->import->import($this->imported(document: ['lines' => [$this->line(), $deduction]]))));
        self::assertSame(0, $this->invoiceCount());
    }

    public function testAnArchivedFirmTakesNoImport(): void
    {
        $this->firm->archive(new \DateTimeImmutable('2026-11-01'));
        $this->em->flush();

        self::assertContains('invoice.problem.firm_archived', $this->keys($this->import->import($this->imported())));
        $noVatId = new DocumentParty('Old Name s.r.o.', '12345695', 'VAT-1', new Address('Old Street 5', 'Old City', '110 00', 'CZ'), '', null, '', '', null, null);
        $keys = $this->keys($this->import->import($this->imported(overrides: ['issuer' => $noVatId])));
        self::assertContains('invoice.problem.firm_archived', $keys);
        self::assertContains('invoice.problem.firm_vat_id_missing', $keys, 'Every problem of the firm is named at once.');
    }

    public function testHalfATaxedPaymentTaxesNothing(): void
    {
        foreach (['taxedAdvanceId' => '0192f0a0-0000-7000-8000-0000000000ab', 'taxedPaymentId' => '0192f0a0-0000-7000-8000-0000000000ac'] as $field => $id) {
            $result = $this->import->import($this->imported(document: ['typedNumber' => 'taxedAdvanceId' === $field ? '20240007' : '20240008'], overrides: [$field => $id, 'externalId' => $field]));

            self::assertSame(ImportOutcome::Imported, $result->outcome, $field);
            self::assertNull($this->stored($result)->paymentId(), $field);
        }
    }

    public function testTheRoundingOfTheDocumentDecidesItsLimits(): void
    {
        // Rounded to whole units, as the firm rounds, the total would pass the largest amount.
        $result = $this->import->import($this->imported(lines: [$this->line('999999999999.60', treatment: TaxTreatment::NotApplicable, kind: null)], overrides: [
            'regime' => VatRegime::NonPayer,
            'rounding' => TotalRounding::None,
            'expected' => new ImportedTotals(99_999_999_999_960, 0, 99_999_999_999_960, 99_999_999_999_960),
        ]));

        self::assertSame(ImportOutcome::Imported, $result->outcome, implode(', ', $this->keys($result)));
    }

    public function testTheRegimeAndTheRoundingAreThoseOfTheDocument(): void
    {
        $nonPayer = $this->import->import($this->imported(lines: [$this->line('1000.40', treatment: TaxTreatment::NotApplicable, kind: null)], overrides: [
            'regime' => VatRegime::NonPayer,
            'rounding' => TotalRounding::None,
            'expected' => new ImportedTotals(100040, 0, 100040, 100040),
        ]));

        self::assertSame(ImportOutcome::Imported, $nonPayer->outcome);
        $invoice = $this->stored($nonPayer);
        self::assertSame(VatRegime::NonPayer, $invoice->vatRegime());
        self::assertSame(TotalRounding::None, $invoice->rounding());
    }

    public function testAnAdvanceInvoiceIsImportedWithoutATaxPoint(): void
    {
        $series = new Series($this->firm, 'Advances', SeriesFormat::fromString('Z9{YYYY}{NNNN}'), DocumentKind::Advance);
        $this->em->persist($series);
        $this->em->flush();

        $result = $this->import->import($this->imported(document: ['kind' => DocumentKind::Advance, 'seriesId' => $series->id(), 'typedNumber' => 'Z920240003', 'taxPointDate' => null]));

        self::assertSame(ImportOutcome::Imported, $result->outcome);
        self::assertSame(DocumentKind::Advance, $this->stored($result)->kind());
        self::assertNull($this->stored($result)->taxPointDate());
    }

    public function testTheOriginalIsKeptAndIsTheDocumentsPdf(): void
    {
        $invoice = $this->stored($this->import->import($this->imported(pdf: self::PDF)));

        self::assertSame(hash('sha256', self::PDF), $invoice->originalPdfSha256());
        $rendered = self::getContainer()->get(InvoicePdf::class)->render(self::getContainer()->get(IssuedDocumentFactory::class)->of($invoice->id()));
        self::assertSame(self::PDF, $rendered->bytes);
        self::assertSame('20240007.pdf', $rendered->fileName);
    }

    public function testAnOriginalThatChangedIsNotShown(): void
    {
        $invoice = $this->stored($this->import->import($this->imported(pdf: self::PDF)));
        $tenant = self::getContainer()->get(TenantContext::class)->current()->toString();
        self::getContainer()->get(OriginalDocuments::class)->store($tenant, $invoice->id(), 'something else');

        $this->expectException(UndrawableDocument::class);
        self::getContainer()->get(InvoicePdf::class)->render(self::getContainer()->get(IssuedDocumentFactory::class)->of($invoice->id()));
    }

    public function testThePackTakesTheOriginalAndDrawsTheElectronicFile(): void
    {
        $this->import->import($this->imported(document: ['issueDate' => new \DateTimeImmutable('2026-10-01'), 'taxPointDate' => new \DateTimeImmutable('2026-10-01'), 'typedNumber' => '20260050'], overrides: ['exportedAt' => null], pdf: self::PDF));
        $path = (string) tempnam(sys_get_temp_dir(), 'foresi-test-');

        try {
            self::assertSame(1, self::getContainer()->get(InvoiceBundle::class)->writeTo($path, $this->firm, new \DateTimeImmutable('2026-10-15'), false, 'en'));
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $files = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $files[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
            }
            $zip->close();
        } finally {
            @unlink($path);
        }

        $pdfs = array_values(array_filter($files, static fn (string $name): bool => str_ends_with($name, '.pdf'), \ARRAY_FILTER_USE_KEY));
        $isdocs = array_values(array_filter($files, static fn (string $name): bool => str_ends_with($name, '.isdoc'), \ARRAY_FILTER_USE_KEY));
        self::assertSame([self::PDF], $pdfs);
        self::assertCount(1, $isdocs);
        self::assertStringContainsString('20260050', $isdocs[0]);
    }

    /**
     * @return iterable<string, array{string|null, int}>
     */
    public static function failedSaves(): iterable
    {
        yield 'with an original' => [self::PDF, 1];
        yield 'without one' => [null, 0];
    }

    #[DataProvider('failedSaves')]
    public function testAFailedSaveTakesTheOriginalAlong(?string $pdf, int $removed): void
    {
        $container = self::getContainer();
        $failing = new readonly class($container->get(TransactionalWork::class)) implements TransactionalWork {
            public function __construct(private TransactionalWork $work)
            {
            }

            public function run(callable $work): mixed
            {
                $this->work->run($work);

                throw new \RuntimeException('The commit failed.');
            }
        };
        $files = new class implements OriginalDocuments {
            /** @var list<string> */
            public array $removed = [];

            public function store(string $tenantId, string $invoiceId, string $bytes): string
            {
                return $tenantId.'/'.$invoiceId.'.pdf';
            }

            public function read(string $tenantId, string $key, string $sha256): string
            {
                return '';
            }

            public function remove(string $tenantId, string $invoiceId): void
            {
                $this->removed[] = $invoiceId;
            }
        };
        $import = new ImportIssuedDocument(
            $container->get(LegalEntities::class),
            $container->get(SeriesCatalog::class),
            $container->get(PartyQuery::class),
            $container->get(IssueRules::class),
            $container->get(CustomerCopies::class),
            $container->get(LinePricing::class),
            $container->get(InvoicingCountries::class),
            $this->store,
            $container->get(ImportedDocuments::class),
            $files,
            $container->get(NumberAllocator::class),
            $failing,
            $container->get(ConflictRetry::class),
            $container->get(AuditActor::class),
            $container->get(ClockInterface::class),
            $container->get(ContactSettingsForFirm::class),
            $container->get(TenantContext::class),
            $container->get(AdvanceDeductions::class),
            $container->get(AdvanceDocuments::class),
            $container->get(InvoicePayments::class),
        );

        try {
            $import->import($this->imported(pdf: $pdf));
            self::fail('Expected the save to fail.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The commit failed.', $failure->getMessage());
        }
        self::assertCount($removed, $files->removed);
    }

    public function testARunIsTakenBackWithItsOriginals(): void
    {
        $first = $this->stored($this->import->import($this->imported(pdf: self::PDF)));
        $this->import->import($this->imported(document: ['typedNumber' => '20240008'], overrides: ['externalId' => '4712']));
        $other = $this->import->import($this->imported(document: ['typedNumber' => '20240009'], overrides: ['externalId' => '4713', 'runId' => '0192f0a0-0000-7000-8000-000000000002']));
        $tenant = self::getContainer()->get(TenantContext::class)->current()->toString();
        $key = (string) $first->originalPdfKey();

        self::assertSame([], $this->undo->undo(self::RUN));

        self::assertSame(1, $this->invoiceCount());
        self::assertSame($other->invoiceId, $this->em->createQuery('SELECT i.id FROM '.Invoice::class.' i')->getSingleScalarResult());
        $this->expectException(\App\Invoicing\Application\OriginalDocumentUnreadable::class);
        self::getContainer()->get(OriginalDocuments::class)->read($tenant, $key, hash('sha256', self::PDF));
    }

    public function testARunIsKeptWhileAnythingStandsOnIt(): void
    {
        $paid = $this->stored($this->import->import($this->imported()));
        $edited = $this->stored($this->import->import($this->imported(document: ['typedNumber' => '20240008'], overrides: ['externalId' => '4712'])));
        $exported = $this->stored($this->import->import($this->imported(document: ['typedNumber' => '20240009'], overrides: ['externalId' => '4713'])));
        $this->pay($paid, '1210.00');
        $this->edited($edited, $this->imported(document: ['typedNumber' => '20240008', 'description' => 'Changed'])->document);
        Clock::set(new MockClock('2026-11-20 10:05:00', 'UTC'));
        self::getContainer()->get(DocumentExports::class)->markExported([$exported->id()]);

        $blockers = $this->undo->undo(self::RUN);

        self::assertEquals([
            new UndoBlocker($paid->id(), '20240007', UndoBlocker::PAYMENTS),
            new UndoBlocker($edited->id(), '20240008', UndoBlocker::CHANGED),
            new UndoBlocker($exported->id(), '20240009', UndoBlocker::EXPORTED),
        ], $blockers);
        self::assertSame(3, $this->invoiceCount());
    }

    public function testADocumentExportedThereBeforeTheImportIsTakenBack(): void
    {
        $this->import->import($this->imported(overrides: ['exportedAt' => new \DateTimeImmutable('2026-11-20 10:00:00')]));

        self::assertSame([], $this->undo->undo(self::RUN), 'Only an export after the import stands on it.');
    }

    public function testARunIsKeptAfterThirtyDays(): void
    {
        $invoice = $this->stored($this->import->import($this->imported()));
        Clock::set(new MockClock('2026-12-20 10:00:01', 'UTC'));

        self::assertEquals([new UndoBlocker($invoice->id(), '20240007', UndoBlocker::EXPIRED)], $this->undo->undo(self::RUN));
        Clock::set(new MockClock('2026-12-20 10:00:00', 'UTC'));
        self::assertSame([], $this->undo->undo(self::RUN));
    }

    /**
     * @param list<LineInput>|null $lines
     * @param array<string, mixed> $document  named arguments of InvoiceInput
     * @param array<string, mixed> $overrides named arguments of ImportedDocumentInput
     */
    private function imported(?array $lines = null, array $document = [], array $overrides = [], ?string $pdf = null): ImportedDocumentInput
    {
        $customer = new DocumentParty('Customer As Printed a.s.', '87654320', 'CZ87654320', new Address('Customer Street 2', 'Customer City', '200 00', 'CZ'), '', 'billing@example.com', '', '', null, null);

        return new \ReflectionClass(ImportedDocumentInput::class)->newInstanceArgs(array_merge([
            'document' => $this->input($lines, array_merge([
                'typedNumber' => '20240007',
                'customerOnDocument' => $customer,
                'issueDate' => new \DateTimeImmutable('2024-03-01'),
                'taxPointDate' => new \DateTimeImmutable('2024-03-01'),
                'dueDate' => new \DateTimeImmutable('2024-03-15'),
                'variableSymbol' => '20240007',
            ], $document)),
            'issuer' => new DocumentParty('Old Name s.r.o.', '12345695', 'CZ12345695', new Address('Old Street 5', 'Old City', '110 00', 'CZ'), '', 'office@example.com', '', '', null, null),
            'regime' => VatRegime::Payer,
            'rounding' => TotalRounding::WholeUnit,
            'createdAt' => new \DateTimeImmutable('2024-03-01 09:30:00'),
            'deliveredAt' => new \DateTimeImmutable('2024-03-02 08:00:00'),
            'exportedAt' => new \DateTimeImmutable('2024-04-05 10:00:00'),
            'expected' => new ImportedTotals(100000, 21000, 121000, 121000),
            'source' => 'idoklad',
            'externalId' => '4711',
            'runId' => self::RUN,
            'originalPdf' => $pdf,
        ], $overrides));
    }

    private function stored(ImportedDocumentResult $result): Invoice
    {
        return $this->store->get($result->invoiceId ?? self::fail('Expected a stored document, got '.$result->outcome->value.': '.implode(', ', $this->keys($result))));
    }

    /**
     * @return list<string>
     */
    private function keys(ImportedDocumentResult $result): array
    {
        return array_map(static fn (IssueProblem $problem): string => $problem->key, $result->problems);
    }

    private function invoiceCount(): int
    {
        $this->em->clear();

        return (int) $this->em->createQuery('SELECT COUNT(i.id) FROM '.Invoice::class.' i')->getSingleScalarResult();
    }
}
