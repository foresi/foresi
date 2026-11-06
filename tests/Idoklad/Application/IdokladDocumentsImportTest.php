<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Application;

use App\Idoklad\Application\IdokladFiles;
use App\Idoklad\Application\Import\DocumentsStage;
use App\Idoklad\Application\Import\RunIdokladImport;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportMode;
use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issued invoices and proformas of a snapshot as built after the list models
 * of the iDoklad API v3 documentation.
 */
final class IdokladDocumentsImportTest extends KernelTestCase
{
    private const int CZECHIA = 2;
    private const int CZK = 1;
    private const int EUR = 2;
    private const int TRANSFER = 1;
    private const int CASH_ON_DELIVERY = 2;
    private const int SERVICES = 7;
    private const int CUSTOMER = 501;

    private EntityManagerInterface $em;
    private LegalEntity $firm;
    private TenantId $tenant;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->tenant = TenantId::generate();
        $container->get(TenantContext::class)->switchTo($this->tenant);

        $this->firm = new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', '12345679'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($this->firm);
        $this->record(IdokladResource::Countries, self::CZECHIA, ['Id' => self::CZECHIA, 'Code' => 'CZE', 'Name' => 'Česká republika', 'NameEnglish' => 'Czech Republic', 'CurrencyId' => self::CZK]);
        $this->record(IdokladResource::Currencies, self::CZK, ['Id' => self::CZK, 'Code' => 'CZK', 'Symbol' => 'Kč']);
        $this->record(IdokladResource::Currencies, self::EUR, ['Id' => self::EUR, 'Code' => 'EUR', 'Symbol' => '€']);
        $this->record(IdokladResource::PaymentOptions, self::TRANSFER, ['Id' => self::TRANSFER, 'Code' => 'B', 'Name' => 'Převodem', 'IsDefault' => true]);
        $this->record(IdokladResource::PaymentOptions, self::CASH_ON_DELIVERY, ['Id' => self::CASH_ON_DELIVERY, 'Code' => 'D', 'Name' => 'Dobírka', 'IsDefault' => false]);
        $this->record(IdokladResource::PaymentOptions, 3, ['Id' => 3, 'Code' => 'H', 'Name' => 'Hotově', 'IsDefault' => false]);
        $this->record(IdokladResource::ConstantSymbols, self::SERVICES, ['Id' => self::SERVICES, 'Code' => '0308', 'Name' => 'Služby', 'CountryId' => self::CZECHIA]);
        $this->record(IdokladResource::Contacts, self::CUSTOMER, ['Id' => self::CUSTOMER, 'CompanyName' => 'Example Trading s.r.o.', 'IdentificationNumber' => '23456787', 'VatIdentificationNumber' => 'CZ23456787', 'Email' => 'billing@example.com', 'Street' => 'Example Street 9', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => self::CZECHIA]);
        $this->record(IdokladResource::NumericSequences, 1, ['Id' => 1, 'DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 9, 'IsDefault' => true]);
        $this->record(IdokladResource::NumericSequences, 2, ['Id' => 2, 'DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2019, 'LastNumber' => 3, 'IsDefault' => true]);
        $this->record(IdokladResource::NumericSequences, 3, ['Id' => 3, 'DocumentType' => 1, 'Name' => 'Zálohy', 'NumberFormat' => 'ZRRRRNNNN', 'Year' => 2026, 'LastNumber' => 2, 'IsDefault' => true]);
        $pull = new IdokladPull($this->firm, new \DateTimeImmutable('2026-11-06 09:00:00'));
        $pull->finish(new \DateTimeImmutable('2026-11-06 09:05:00'), 3);
        $this->em->persist($pull);
        $this->em->flush();
    }

    public function testAMatchingInvoiceIsImportedAsIDokladPrintedIt(): void
    {
        $this->invoice(101, ['IsSentToPurchaser' => 0]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertNull($run->error());
        $entry = $this->entry($run, 101);
        self::assertSame('created', $entry['outcome'], implode(', ', $entry['reasons']));
        self::assertSame(['resource' => 'IssuedInvoices', 'document_number' => '20260008', 'sent_to_purchaser' => 0], $entry['values']);
        $invoice = $this->invoiceOf($entry['target_id']);
        self::assertSame(DocumentKind::Invoice, $invoice->kind());
        self::assertSame('20260008', $invoice->number());
        self::assertSame('{YYYY}{NNNN}', $invoice->series()->format()->toString());
        self::assertSame(VatRegime::Payer, $invoice->vatRegime());
        self::assertSame(TotalRounding::None, $invoice->rounding());
        self::assertSame('2026-10-01', $invoice->taxPointDate()?->format('Y-m-d'));
        self::assertSame(242000, $invoice->payable()->minor());
        self::assertSame(200000, $invoice->net()->minor());
        self::assertSame('Example Trading As Printed s.r.o.', $invoice->customer()->name);
        self::assertSame('CZ23456787', $invoice->customer()->vatId);
        self::assertSame('Example Old Name s.r.o.', $invoice->issuer()->name);
        self::assertSame('CZ12345679', $invoice->issuer()->vatId);
        self::assertSame('Old Street 5', $invoice->issuer()->address->street);
        self::assertSame('office@example.com', $invoice->issuer()->email);
        self::assertSame(PaymentMethod::BankTransfer, $invoice->paymentMethod());
        self::assertSame('CZ6508000000192000145399', $invoice->bankAccount());
        self::assertSame('2026-10-15', $invoice->dueDate()->format('Y-m-d'));
        self::assertSame('20260008', $invoice->variableSymbol());
        self::assertSame('0308', $invoice->constantSymbol());
        self::assertSame('PO-1', $invoice->orderNumber());
        self::assertSame('We invoice you for:', $invoice->textBefore());
        self::assertSame(DocumentLanguage::Czech, $invoice->language());
        self::assertSame('2026-10-01 09:00:00', $invoice->createdAt()->format('Y-m-d H:i:s'));
        self::assertSame('idoklad', $invoice->importSource());
        self::assertSame('IssuedInvoices/101', $invoice->importExternalId());
        self::assertSame($run->id(), $invoice->importRunId());
        self::assertNull($invoice->deliveredAt());
        self::assertNull($invoice->exportedAt());
        self::assertNull($invoice->originalPdfKey());
        self::assertSame(['contacts' => ['created' => 1], 'series' => ['created' => 2, 'matched' => 1], 'documents' => ['created' => 1]], $run->counts());
        $this->em->clear();
        self::assertSame('20260010', self::getContainer()->get(NumberCounter::class)->peek($this->invoiceOf($entry['target_id'])->series(), new \DateTimeImmutable('2026-12-01')), 'The counter stays at iDoklad\'s last number.');
    }

    public function testA2019InvoiceTakesTheRatesOfItsDayAndPricesWithVat(): void
    {
        $this->invoice(102, [
            'DocumentNumber' => '20190003',
            'VariableSymbol' => '20190003',
            'DateOfIssue' => '2019-05-01T00:00:00',
            'DateOfTaxing' => '2019-05-01T00:00:00',
            'DateOfMaturity' => '2019-05-15T00:00:00',
            'ConstantSymbolId' => null,
            'PartnerAddress' => ['NickName' => '', 'Title' => 'Ing.', 'Firstname' => 'Jana', 'Surname' => 'Example', 'Email' => '', 'Phone' => '', 'Mobile' => '+420 777 000 111', 'Street' => 'Example Street 9', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => self::CZECHIA],
            'PaymentOptionId' => 3,
            'Items' => [
                $this->item('Books', 1, 115.0, 0, 0, 15.0, 100.0, 15.0),
                $this->item('Heating', 2, 100.0, 1, 3, 10.0, 200.0, 20.0),
            ],
            'Prices' => ['TotalWithoutVat' => 300.0, 'TotalVat' => 35.0, 'TotalWithVat' => 335.0],
        ]);

        $entry = $this->entry($this->import()->run($this->firm, ImportMode::Run), 102);

        self::assertSame('created', $entry['outcome'], implode(', ', $entry['reasons']));
        $invoice = $this->invoiceOf($entry['target_id']);
        self::assertSame(33500, $invoice->payable()->minor());
        self::assertSame(30000, $invoice->net()->minor());
        self::assertSame('Ing. Jana Example', $invoice->customer()->name, 'A person without a nickname is named from the parts.');
        self::assertNull($invoice->customer()->email);
        self::assertSame('+420 777 000 111', $invoice->customer()->phone);
        self::assertNull($invoice->customer()->registrationNumber);
        self::assertSame(PaymentMethod::Cash, $invoice->paymentMethod());
        self::assertNull($invoice->bankAccount(), 'Only a transfer names the account.');
        self::assertSame('', $invoice->constantSymbol());
    }

    public function testOtherTotalsThanIDokladsAreAMismatchWithBoth(): void
    {
        $this->invoice(103, ['Prices' => ['TotalWithoutVat' => 2000.0, 'TotalVat' => 421.0, 'TotalWithVat' => 2421.0]]);

        $entry = $this->entry($this->import()->run($this->firm, ImportMode::Run), 103);

        self::assertSame('mismatch', $entry['outcome']);
        self::assertNull($entry['target_id']);
        self::assertSame(42100, $entry['values']['expected_vat']);
        self::assertSame(42000, $entry['values']['computed_vat']);
        self::assertSame(242100, $entry['values']['expected_payable']);
        self::assertSame(242000, $entry['values']['computed_payable']);
        self::assertSame(200000, $entry['values']['computed_net']);
        self::assertSame(242000, $entry['values']['computed_gross']);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM invoice'));
    }

    public function testAnAdvanceWithoutTaxPointOfANonPayerKeepsItsRoundingItem(): void
    {
        $this->record(IdokladResource::ProformaInvoices, 201, $this->document([
            'DocumentNumber' => 'Z20260003',
            'VariableSymbol' => '20260003',
            'VatRegime' => 0,
            'Items' => [
                $this->item('Deposit', 1, 999.6, 1, 2, 0.0, 999.6, 0.0),
                ['Id' => 2, 'Name' => 'Rounding', 'Amount' => 1.0, 'ItemType' => 1, 'PriceType' => 2, 'VatRateType' => 2, 'VatRate' => 0.0, 'Prices' => ['UnitPrice' => 0.4, 'TotalWithoutVat' => 0.4, 'TotalVat' => 0.0, 'TotalWithVat' => 0.4]],
            ],
            'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 0.0, 'TotalWithVat' => 1000.0],
        ], ['DateOfTaxing']));

        $entry = $this->entry($this->import()->run($this->firm, ImportMode::Run), 201);

        self::assertSame('created', $entry['outcome'], implode(', ', $entry['reasons']));
        $advance = $this->invoiceOf($entry['target_id']);
        self::assertSame(DocumentKind::Advance, $advance->kind());
        self::assertSame('Z20260003', $advance->number());
        self::assertSame(VatRegime::NonPayer, $advance->vatRegime());
        self::assertSame(TotalRounding::WholeUnit, $advance->rounding());
        self::assertNull($advance->taxPointDate());
        self::assertSame(100000, $advance->payable()->minor());
        self::assertSame(40, $advance->totalRounding()->minor());
        self::assertSame('ProformaInvoices/201', $advance->importExternalId());
    }

    public function testSentReadAndExportedMarksComeFromTheLogOrTheirFallbacks(): void
    {
        $this->invoice(104, ['IsSentToPurchaser' => 2, 'Exported' => 1]);
        $this->record(IdokladResource::Logs, 104, ['Id' => 104, 'EntityType' => 0, 'ActionType' => 8, 'DateOfAction' => '2026-10-02T10:00:00']);
        $this->invoice(105, ['DocumentNumber' => '20260009', 'VariableSymbol' => '20260009', 'IsSentToPurchaser' => 1, 'Exported' => 1, 'Metadata' => []]);
        $this->record(IdokladResource::Logs, 105, ['Id' => 105, 'EntityType' => 0, 'ActionType' => 11, 'DateOfAction' => '2026-10-20T12:30:00']);
        $this->invoice(106, ['DocumentNumber' => '20260010', 'VariableSymbol' => '20260010', 'IsSentToPurchaser' => 1, 'Exported' => 2]);
        $this->record(IdokladResource::Logs, 106, ['Id' => 106, 'EntityType' => 1, 'ActionType' => 8, 'DateOfAction' => '2026-10-02T10:00:00']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $read = $this->invoiceOf($this->entry($run, 104)['target_id']);
        self::assertSame(2, $this->entry($run, 104)['values']['sent_to_purchaser'], 'Read is kept for the history.');
        self::assertEquals(new \DateTimeImmutable('2026-10-02 10:00:00', new \DateTimeZone('Europe/Prague')), $read->deliveredAt());
        self::assertEquals($run->startedAt(), $read->exportedAt(), 'Without its log entry the export is the run\'s.');
        $sent = $this->invoiceOf($this->entry($run, 105)['target_id']);
        self::assertSame('2026-10-01 00:00:00', $sent->deliveredAt()?->format('Y-m-d H:i:s'), 'Without its log entry a send is the issue date.');
        self::assertEquals(new \DateTimeImmutable('2026-10-20 12:30:00', new \DateTimeZone('Europe/Prague')), $sent->exportedAt());
        self::assertSame('2026-10-01 00:00:00', $sent->createdAt()->format('Y-m-d H:i:s'), 'Without its creation, the issue date.');
        $changed = $this->invoiceOf($this->entry($run, 106)['target_id']);
        self::assertSame('2026-10-01 00:00:00', $changed->deliveredAt()?->format('Y-m-d H:i:s'), 'A log entry of a proforma of the same id is not the invoice\'s.');
        self::assertNull($changed->exportedAt(), 'Changed after its export is to be exported again.');
    }

    public function testOnlyHomeCurrencyDocumentsWithoutAdvancesAndNoCreditNotesAreImported(): void
    {
        $this->invoice(107, ['CurrencyId' => self::EUR]);
        $this->invoice(108, ['DocumentNumber' => '20260009', 'ProformaInvoices' => [201]]);
        $this->invoice(109, ['DocumentNumber' => '20260010', 'Items' => [$this->item('Consulting', 2, 1000.0, 1, 1, 21.0, 2000.0, 420.0), ['Id' => 3, 'Name' => 'Advance', 'Amount' => -1.0, 'ItemType' => 2, 'InvoiceProformaId' => 201, 'PriceType' => 1, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 100.0]]]]);
        $this->record(IdokladResource::CreditNotes, 301, ['Id' => 301, 'DocumentNumber' => 'D20260001']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame([DocumentsStage::CURRENCY_NOT_HOME], $this->entry($run, 107)['reasons']);
        self::assertSame('EUR', $this->entry($run, 107)['values']['currency']);
        self::assertSame([DocumentsStage::ADVANCE_NOT_LINKED], $this->entry($run, 108)['reasons']);
        self::assertSame([DocumentsStage::ADVANCE_NOT_LINKED], $this->entry($run, 109)['reasons']);
        $credit = $this->entry($run, 301);
        self::assertSame([DocumentsStage::CREDIT_NOTE_UNSUPPORTED], $credit['reasons']);
        self::assertSame(['resource' => 'CreditNotes', 'document_number' => 'D20260001'], $credit['values']);
        self::assertSame(['refused' => 4], $run->counts()['documents']);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM invoice'));
    }

    public function testWhatCannotBeReadOrIsRefusedSaysWhy(): void
    {
        $this->invoice(110, ['PartnerId' => 999, 'DocumentNumber' => 'X-1', 'PaymentOptionId' => self::CASH_ON_DELIVERY, 'ReportLanguage' => 2]);
        $this->invoice(111, ['DocumentNumber' => '20260009', 'MyAddress' => ['NickName' => 'Example s.r.o.', 'CountryId' => 99], 'Items' => [$this->item('Export', 1, 100.0, 1, 2, 0.0, 100.0, 0.0)]]);
        $this->invoice(112, ['DocumentNumber' => '20260010', 'Items' => [['Id' => 1, 'Name' => 'Broken', 'Amount' => 'one', 'ItemType' => 0, 'PriceType' => 1, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 1.0]]]]);
        $this->invoice(113, ['DocumentNumber' => '20260011', 'DateOfIssue' => 'soon', 'Items' => [['Id' => 1, 'Name' => 'Typed', 'Amount' => 1.0, 'ItemType' => 0, 'PriceType' => 9, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 1.0]]]]);
        $this->invoice(114, ['DocumentNumber' => '20260012', 'NumericSequenceId' => 77]);
        $this->invoice(115, ['DocumentNumber' => '20260013', 'MyAddress' => $this->myAddress(['VatIdentificationNumber' => ''])]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame([DocumentsStage::SERIES_UNKNOWN, DocumentsStage::CUSTOMER_NOT_LINKED, DocumentsStage::PAYMENT_OPTION_UNSUPPORTED, DocumentsStage::LANGUAGE_UNSUPPORTED], $this->entry($run, 110)['reasons']);
        self::assertSame('Dobírka', $this->entry($run, 110)['values']['payment_option']);
        self::assertSame([DocumentsStage::COUNTRY_UNKNOWN, DocumentsStage::ZERO_RATE_UNSUPPORTED], $this->entry($run, 111)['reasons']);
        self::assertSame([DocumentsStage::INVALID_VALUE], $this->entry($run, 112)['reasons']);
        self::assertSame([DocumentsStage::SERIES_UNKNOWN, DocumentsStage::INVALID_VALUE], $this->entry($run, 113)['reasons']);
        self::assertSame([DocumentsStage::SERIES_UNKNOWN], $this->entry($run, 114)['reasons'], 'A sequence id of the document names its series.');
        self::assertSame(['invoice.problem.firm_vat_id_missing'], $this->entry($run, 115)['reasons'], 'What the import of Invoicing refuses is reported as it says.');
        self::assertSame(['refused' => 6], $run->counts()['documents']);
    }

    public function testDocumentsAreImportedInTheOrderOfTheirNumbers(): void
    {
        $this->invoice(120, ['DocumentNumber' => '20260011', 'VariableSymbol' => '20260011']);
        $this->invoice(121, ['DocumentNumber' => '20260010', 'VariableSymbol' => '20260010', 'NumericSequenceId' => 1]);
        $this->invoice(122, ['DocumentNumber' => '20260009', 'VariableSymbol' => '20260009', 'DateOfIssue' => '2026-10-02']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $documents = array_values(array_filter($run->report(), static fn (array $entry): bool => DocumentsStage::KEY === $entry['stage']));
        self::assertSame([121, 120, 122], array_column($documents, 'external_id'), 'By issue date, then number.');
        self::assertSame(['created', 'created', 'created'], array_column($documents, 'outcome'));
    }

    public function testACheckWritesNoDocumentAndReadsNoFile(): void
    {
        $this->invoice(130, []);
        $record = $this->em->getRepository(IdokladRecord::class)->findOneBy(['resource' => IdokladResource::IssuedInvoices, 'externalId' => 130]) ?? self::fail('The record is stored.');
        $record->attachPdf($this->tenant->toString().'/'.$record->id().'.pdf', str_repeat('0', 64));
        $this->em->flush();

        $check = $this->import()->run($this->firm, ImportMode::Check);

        self::assertSame('created', $this->entry($check, 130)['outcome'], 'A check reads no PDF, so a broken one does not show.');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM invoice'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM idoklad_link'));

        $run = $this->import()->run($this->storedFirm(), ImportMode::Run);

        self::assertSame([DocumentsStage::PDF_UNREADABLE], $this->entry($run, 130)['reasons']);
    }

    public function testARunKeepsThePdfAndASecondRunFindsTheDocument(): void
    {
        $this->invoice(131, []);
        $record = $this->em->getRepository(IdokladRecord::class)->findOneBy(['resource' => IdokladResource::IssuedInvoices, 'externalId' => 131]) ?? self::fail('The record is stored.');
        $bytes = '%PDF-1.4 example';
        $record->attachPdf(self::getContainer()->get(IdokladFiles::class)->store($this->tenant->toString(), $record->id(), $bytes), hash('sha256', $bytes));
        $this->em->flush();

        $first = $this->import()->run($this->firm, ImportMode::Run);
        $id = $this->entry($first, 131)['target_id'];
        self::assertNotNull($this->invoiceOf($id)->originalPdfKey());

        $second = $this->import()->run($this->storedFirm(), ImportMode::Run);
        self::assertSame('already_linked', $this->entry($second, 131)['outcome']);
        self::assertSame($id, $this->entry($second, 131)['target_id']);

        $this->em->getConnection()->executeStatement("DELETE FROM idoklad_link WHERE resource = 'IssuedInvoices'");
        $third = $this->import()->run($this->storedFirm(), ImportMode::Run);
        self::assertSame('matched', $this->entry($third, 131)['outcome'], 'An imported document is linked again.');
        self::assertSame($id, $this->entry($third, 131)['target_id']);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM invoice'));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM idoklad_link WHERE resource = 'IssuedInvoices'"));
    }

    private function import(): RunIdokladImport
    {
        return self::getContainer()->get('test.idoklad_import');
    }

    /**
     * An invoice of 2 hours at 1000 CZK without VAT at 21 %, unless the fields say otherwise.
     *
     * @param array<string, mixed> $fields
     */
    private function invoice(int $id, array $fields): void
    {
        $this->record(IdokladResource::IssuedInvoices, $id, $this->document($fields + ['Id' => $id]));
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string>         $without
     *
     * @return array<string, mixed>
     */
    private function document(array $fields, array $without = []): array
    {
        $document = $fields + [
            'DocumentNumber' => '20260008',
            'DocumentSerialNumber' => 8,
            'VariableSymbol' => '20260008',
            'ConstantSymbolId' => self::SERVICES,
            'CurrencyId' => self::CZK,
            'ExchangeRate' => 1.0,
            'ExchangeRateAmount' => 1.0,
            'DateOfIssue' => '2026-10-01',
            'DateOfTaxing' => '2026-10-01',
            'DateOfMaturity' => '2026-10-15',
            'Description' => 'Consulting',
            'OrderNumber' => 'PO-1',
            'ItemsTextPrefix' => 'We invoice you for:',
            'ItemsTextSuffix' => 'Thank you.',
            'PartnerId' => self::CUSTOMER,
            'PartnerAddress' => ['NickName' => 'Example Trading As Printed s.r.o.', 'IdentificationNumber' => '23456787', 'VatIdentificationNumber' => 'CZ23456787', 'Email' => 'billing@example.com', 'Street' => 'Example Street 9', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => self::CZECHIA],
            'MyAddress' => $this->myAddress([]),
            'PaymentOptionId' => self::TRANSFER,
            'ReportLanguage' => 1,
            'VatRegime' => 1,
            'IsSentToPurchaser' => 0,
            'Exported' => 0,
            'ProformaInvoices' => [],
            'Items' => [$this->item('Consulting', 2, 1000.0, 1, 1, 21.0, 2000.0, 420.0)],
            'Prices' => ['TotalWithoutVat' => 2000.0, 'TotalVat' => 420.0, 'TotalWithVat' => 2420.0, 'TotalWithoutVatHc' => 2000.0, 'TotalVatHc' => 420.0, 'TotalWithVatHc' => 2420.0],
            'Metadata' => ['DateCreated' => '2026-10-01T09:00:00.123', 'DateLastChange' => '2026-10-02T10:39:22.873'],
        ];

        return array_diff_key($document, array_flip($without));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function myAddress(array $fields): array
    {
        return $fields + ['NickName' => 'Example Old Name s.r.o.', 'IdentificationNumber' => '12345679', 'VatIdentificationNumber' => 'CZ12345679', 'Email' => 'office@example.com', 'Phone' => '+420 123 456 789', 'Www' => 'https://example.com', 'Street' => 'Old Street 5', 'City' => 'Old City', 'PostalCode' => '110 00', 'CountryId' => self::CZECHIA, 'Iban' => 'CZ65 0800 0000 1920 0014 5399', 'AccountNumber' => '19-2000145399', 'BankCode' => '0800'];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $name, int|float $amount, float $unitPrice, int $priceType, int $rateType, float $rate, float $net, float $vat): array
    {
        return ['Id' => crc32($name), 'Name' => $name, 'Amount' => (float) $amount, 'Unit' => 'h', 'ItemType' => 0, 'PriceType' => $priceType, 'VatRateType' => $rateType, 'VatRate' => $rate, 'DiscountPercentage' => 0.0, 'Prices' => ['UnitPrice' => $unitPrice, 'TotalWithoutVat' => $net, 'TotalVat' => $vat, 'TotalWithVat' => $net + $vat]];
    }

    /**
     * @param array<mixed> $payload
     */
    private function record(IdokladResource $resource, int $id, array $payload): void
    {
        $this->em->persist(new IdokladRecord($this->firm, $resource, $id, $payload, new \DateTimeImmutable('2026-11-06 09:01:00')));
        $this->em->flush();
    }

    /**
     * @return array{stage: string, external_id: int, outcome: string, target_id: string|null, reasons: list<string>, values: array<string, int|string|bool|null>}
     */
    private function entry(IdokladImportRun $run, int $id): array
    {
        foreach ($run->report() as $entry) {
            if (DocumentsStage::KEY === $entry['stage'] && $id === $entry['external_id']) {
                return $entry;
            }
        }

        self::fail(\sprintf('The report has no document %d.', $id));
    }

    private function invoiceOf(?string $id): Invoice
    {
        return $this->em->find(Invoice::class, (string) $id) ?? self::fail('The document is stored.');
    }

    private function storedFirm(): LegalEntity
    {
        return $this->em->find(LegalEntity::class, $this->firm->id()) ?? self::fail('The firm is stored.');
    }

    private function rows(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
