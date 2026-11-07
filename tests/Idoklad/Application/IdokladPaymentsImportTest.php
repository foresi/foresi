<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Application;

use App\Idoklad\Application\Import\DocumentsStage;
use App\Idoklad\Application\Import\PaymentsStage;
use App\Idoklad\Application\Import\RunIdokladImport;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportMode;
use App\Idoklad\Domain\ImportOptions;
use App\Invoicing\Application\Deductible;
use App\Invoicing\Application\DeductibleAdvances;
use App\Invoicing\Application\UndoImportedDocuments;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
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
 * Payments, tax documents on payment and invoices that deduct advances of a
 * snapshot as built after the list models of the iDoklad API v3 documentation:
 * a proforma of 1 210 CZK paid on 5 October, its tax document, and the invoice
 * of 2 420 CZK that deducts it.
 */
final class IdokladPaymentsImportTest extends KernelTestCase
{
    private const int CZECHIA = 2;
    private const int CZK = 1;
    private const int EUR = 2;
    private const int TRANSFER = 1;
    private const int CASH_ON_DELIVERY = 2;
    private const int SERVICES = 7;
    private const int CUSTOMER = 501;
    private const int PROFORMA = 201;
    private const int PAYMENT = 401;
    private const int TAX_DOCUMENT = 601;
    private const int INVOICE = 101;

    private EntityManagerInterface $em;
    private LegalEntity $firm;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $tenant = TenantId::generate();
        $container->get(TenantContext::class)->switchTo($tenant);

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
        $this->record(IdokladResource::NumericSequences, 4, ['Id' => 4, 'DocumentType' => 10, 'Name' => 'Daňové doklady', 'NumberFormat' => 'DRRRRNNNN', 'Year' => 2026, 'LastNumber' => 0, 'IsDefault' => true]);
        $pull = new IdokladPull($this->firm, new \DateTimeImmutable('2026-11-06 09:00:00'));
        $pull->finish(new \DateTimeImmutable('2026-11-06 09:05:00'), 3);
        $this->em->persist($pull);
        $this->em->flush();
    }

    public function testAPaymentIsImportedOnItsDayWithoutANewTaxDocument(): void
    {
        $this->proforma();
        $this->payment(self::PAYMENT, []);

        $run = $this->import()->run($this->firm, ImportMode::Run, $this->payer());

        self::assertNull($run->error());
        $entry = $this->entry($run, self::PAYMENT, PaymentsStage::KEY);
        self::assertSame('created', $entry['outcome'], implode(', ', $entry['reasons']));
        self::assertSame(['document_type' => 1, 'document_id' => self::PROFORMA, 'document_number' => 'Z20260003'], $entry['values']);
        $payment = $this->em->find(Payment::class, (string) $entry['target_id']) ?? self::fail('The payment is stored.');
        self::assertSame('2026-10-05', $payment->receivedOn()->format('Y-m-d'));
        self::assertSame(121000, $payment->amount()->minor());
        self::assertSame(PaymentMethod::BankTransfer, $payment->method());
        self::assertSame('IssuedDocumentPayments/401', $payment->importExternalId());
        self::assertSame($run->id(), $payment->importRunId());
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM invoice WHERE kind = 'payment_tax_document'"), 'Foresi issues no tax document of its own.');
        self::assertSame(['contacts' => ['created' => 1], 'series' => ['created' => 3, 'matched' => 1], 'documents' => ['created' => 1], 'payments' => ['created' => 1]], $run->counts());
    }

    public function testATaxDocumentIsLinkedToItsPaymentAndAdvanceAndStaysDeductible(): void
    {
        $this->proforma();
        $this->payment(self::PAYMENT, []);
        $this->taxDocument(self::TAX_DOCUMENT, []);

        $run = $this->import()->run($this->firm, ImportMode::Run, $this->payer());

        $entry = $this->entry($run, self::TAX_DOCUMENT);
        self::assertSame('created', $entry['outcome'], implode(', ', $entry['reasons']));
        $tax = $this->invoiceOf($entry['target_id']);
        self::assertSame(DocumentKind::PaymentTaxDocument, $tax->kind());
        self::assertSame('D20260001', $tax->number());
        self::assertSame($this->entry($run, self::PROFORMA)['target_id'], $tax->advanceInvoiceId());
        self::assertSame($this->entry($run, self::PAYMENT, PaymentsStage::KEY)['target_id'], $tax->paymentId());
        self::assertSame('2026-10-05', $tax->taxPointDate()?->format('Y-m-d'));
        self::assertSame([100000, 21000, 121000], [$tax->net()->minor(), $tax->vat()->minor(), $tax->gross()->minor()]);
        self::assertSame('IssuedTaxDocuments/601', $tax->importExternalId());
        $deductible = self::getContainer()->get(DeductibleAdvances::class)->for($this->storedFirm(), $tax->customerParty()->id(), null, DocumentLanguage::Czech);
        self::assertSame([$tax->id()], array_map(static fn (Deductible $offered): string => $offered->id, $deductible), 'The advance is open: its tax document waits for the final invoice.');
    }

    public function testAFinalInvoiceDeductsTheTaxDocumentAndSettlesTheAdvance(): void
    {
        $this->proforma();
        $this->payment(self::PAYMENT, []);
        $this->taxDocument(self::TAX_DOCUMENT, []);
        $this->finalInvoice([]);

        $run = $this->import()->run($this->firm, ImportMode::Run, $this->payer());

        $entry = $this->entry($run, self::INVOICE);
        self::assertSame('created', $entry['outcome'], implode(', ', $entry['reasons']));
        $invoice = $this->invoiceOf($entry['target_id']);
        self::assertSame([100000, 21000, 121000, 121000], [$invoice->net()->minor(), $invoice->vat()->minor(), $invoice->gross()->minor(), $invoice->payable()->minor()]);
        self::assertTrue($invoice->deductsAdvances());
        $this->em->clear();
        self::assertSame($invoice->id(), $this->invoiceOf($this->entry($run, self::TAX_DOCUMENT)['target_id'])->deductedByInvoiceId());
        self::assertSame([], self::getContainer()->get(DeductibleAdvances::class)->for($this->storedFirm(), $invoice->customerParty()->id(), null, DocumentLanguage::Czech));
        $documents = array_values(array_filter($run->report(), static fn (array $entry): bool => DocumentsStage::KEY === $entry['stage']));
        self::assertSame([self::PROFORMA, self::TAX_DOCUMENT, self::INVOICE], array_column($documents, 'external_id'), 'The final invoice follows what it deducts.');
    }

    public function testAnAdvanceWithoutVatIsDeductedForWhatWasPaid(): void
    {
        $nonPayer = ['VatRegime' => 0, 'Items' => [$this->item('Deposit', 1, 1000.0, 1, 2, 0.0, 1000.0, 0.0)], 'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 0.0, 'TotalWithVat' => 1000.0]];
        $this->record(IdokladResource::ProformaInvoices, self::PROFORMA, $this->document(['Id' => self::PROFORMA, 'DocumentNumber' => 'Z20260003', 'VariableSymbol' => '20260003'] + $nonPayer, ['DateOfTaxing']));
        $this->payment(self::PAYMENT, ['Prices' => ['PaymentAmount' => 1000.0]]);
        $this->finalInvoice([
            'VatRegime' => 0,
            'Items' => [
                $this->item('Consulting', 2, 1000.0, 1, 2, 0.0, 2000.0, 0.0),
                ['Id' => 9, 'Name' => 'Deduction of Z20260003', 'Amount' => -1.0, 'ItemType' => 2, 'InvoiceProformaId' => self::PROFORMA, 'PriceType' => 1, 'VatRateType' => 2, 'VatRate' => 0.0, 'Prices' => ['UnitPrice' => 1000.0, 'TotalWithoutVat' => -1000.0, 'TotalVat' => 0.0, 'TotalWithVat' => -1000.0]],
            ],
            'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 0.0, 'TotalWithVat' => 1000.0],
        ]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, self::INVOICE);
        self::assertSame('created', $entry['outcome'], implode(', ', $entry['reasons']).json_encode($entry['values']));
        $this->em->clear();
        self::assertSame($entry['target_id'], $this->invoiceOf($this->entry($run, self::PROFORMA)['target_id'])->deductedByInvoiceId(), 'No VAT arose on it, so the advance itself is deducted.');
        self::assertSame(100000, $this->invoiceOf($entry['target_id'])->payable()->minor());
    }

    public function testWhatCannotBeImportedSaysWhy(): void
    {
        $this->proforma();
        $this->payment(405, ['DateOfPayment' => '2099-01-01']);
        $this->payment(403, ['DocumentType' => 3]);
        $this->payment(402, ['InvoiceId' => 999, 'InvoiceDocumentNumber' => 'X-1']);
        $this->payment(404, ['CurrencyId' => self::EUR, 'PaymentOptionId' => self::CASH_ON_DELIVERY, 'Prices' => [], 'DateOfPayment' => '2026-10-04']);
        $this->taxDocument(602, ['PaymentId' => 402, 'ProformaInvoiceId' => 999]);
        $this->finalInvoice(['ProformaInvoices' => [999]]);
        $this->finalInvoice(['Id' => 102, 'DocumentNumber' => '20260009', 'VariableSymbol' => '20260009'], 102);

        $run = $this->import()->run($this->firm, ImportMode::Run, $this->payer());

        self::assertSame([PaymentsStage::DOCUMENT_NOT_LINKED], $this->entry($run, 402, PaymentsStage::KEY)['reasons']);
        self::assertSame([PaymentsStage::DOCUMENT_TYPE_UNSUPPORTED], $this->entry($run, 403, PaymentsStage::KEY)['reasons']);
        $foreign = $this->entry($run, 404, PaymentsStage::KEY);
        self::assertSame([PaymentsStage::CURRENCY_NOT_HOME, PaymentsStage::PAYMENT_OPTION_UNSUPPORTED, PaymentsStage::INVALID_VALUE], $foreign['reasons']);
        self::assertSame('EUR', $foreign['values']['currency']);
        self::assertSame('Dobírka', $foreign['values']['payment_option']);
        self::assertSame(['payment.refused.future_date'], $this->entry($run, 405, PaymentsStage::KEY)['reasons'], 'What the invoicing import refuses is reported as it says.');
        self::assertSame([DocumentsStage::PAYMENT_NOT_LINKED, DocumentsStage::ADVANCE_NOT_LINKED], $this->entry($run, 602)['reasons']);
        self::assertSame([DocumentsStage::ADVANCE_NOT_LINKED, DocumentsStage::ADVANCE_NOT_DEDUCTIBLE], $this->entry($run, self::INVOICE)['reasons'], 'Each advance it accounts, by its list and its items.');
        self::assertSame([DocumentsStage::ADVANCE_NOT_DEDUCTIBLE], $this->entry($run, 102)['reasons'], 'An unpaid advance with VAT has nothing to deduct.');
        self::assertSame(['refused' => 4], $run->counts()['payments']);
        $payments = array_values(array_filter($run->report(), static fn (array $entry): bool => PaymentsStage::KEY === $entry['stage']));
        self::assertSame([404, 402, 403, 405], array_column($payments, 'external_id'), 'By the day of payment, then the id.');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM payment'));
    }

    public function testEachReasonAloneRefusesThePayment(): void
    {
        $this->proforma();
        $this->payment(411, ['CurrencyId' => self::EUR]);
        $this->payment(412, ['PaymentOptionId' => self::CASH_ON_DELIVERY]);
        $this->payment(413, ['Prices' => ['PaymentAmount' => '1210']]);
        $this->payment(414, ['DateOfPayment' => 'soon']);
        $this->payment(415, ['DocumentType' => null]);
        $this->payment(416, ['InvoiceId' => null]);
        $this->payment(417, ['CurrencyId' => null]);
        $this->payment(418, ['CurrencyId' => 999]);
        $this->payment(419, ['PaymentOptionId' => null]);
        $this->payment(420, ['PaymentOptionId' => 999]);

        $run = $this->import()->run($this->firm, ImportMode::Run, $this->payer());

        $reasons = fn (int $id): array => $this->entry($run, $id, PaymentsStage::KEY)['reasons'];
        self::assertSame([PaymentsStage::CURRENCY_NOT_HOME], $reasons(411));
        self::assertSame([PaymentsStage::PAYMENT_OPTION_UNSUPPORTED], $reasons(412));
        self::assertSame([PaymentsStage::INVALID_VALUE], $reasons(413));
        self::assertSame([PaymentsStage::INVALID_VALUE], $reasons(414));
        self::assertSame([PaymentsStage::DOCUMENT_TYPE_UNSUPPORTED], $reasons(415));
        self::assertSame([PaymentsStage::DOCUMENT_NOT_LINKED], $reasons(416));
        self::assertSame([PaymentsStage::CURRENCY_NOT_HOME], $reasons(417));
        self::assertNull($this->entry($run, 417, PaymentsStage::KEY)['values']['currency']);
        self::assertSame([PaymentsStage::CURRENCY_NOT_HOME], $reasons(418));
        self::assertSame([PaymentsStage::PAYMENT_OPTION_UNSUPPORTED], $reasons(419));
        self::assertSame('', $this->entry($run, 419, PaymentsStage::KEY)['values']['payment_option']);
        self::assertSame([PaymentsStage::PAYMENT_OPTION_UNSUPPORTED], $reasons(420));
        self::assertSame(['refused' => 10], $run->counts()['payments']);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM payment'));
    }

    public function testAZeroPaymentIsSkippedAndANegativeOneIsARefundNotImported(): void
    {
        $this->proforma();
        $this->payment(self::PAYMENT, []);
        $this->payment(421, ['Prices' => ['PaymentAmount' => 0.0]]);
        $this->payment(422, ['Prices' => ['PaymentAmount' => -1210.0]]);

        $run = $this->import()->run($this->firm, ImportMode::Run, $this->payer());

        self::assertSame('created', $this->entry($run, self::PAYMENT, PaymentsStage::KEY)['outcome']);
        $zero = $this->entry($run, 421, PaymentsStage::KEY);
        self::assertSame('skipped', $zero['outcome']);
        self::assertSame([PaymentsStage::ZERO_AMOUNT], $zero['reasons']);
        $refund = $this->entry($run, 422, PaymentsStage::KEY);
        self::assertSame('refused', $refund['outcome']);
        self::assertSame([PaymentsStage::REFUND_UNSUPPORTED], $refund['reasons']);
        self::assertSame(-121000, $refund['values']['amount']);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM payment'));
    }

    public function testAWholeAmountAndAnOptionInCapitalsAreImported(): void
    {
        $this->record(IdokladResource::PaymentOptions, 4, ['Id' => 4, 'Code' => 'P', 'Name' => 'PŘEVODEM', 'IsDefault' => false]);
        $this->proforma();
        $this->payment(self::PAYMENT, ['PaymentOptionId' => 4, 'Prices' => ['PaymentAmount' => 1210]]);

        $run = $this->import()->run($this->firm, ImportMode::Run, $this->payer());

        $entry = $this->entry($run, self::PAYMENT, PaymentsStage::KEY);
        self::assertSame('created', $entry['outcome'], implode(', ', $entry['reasons']));
        $payment = $this->em->find(Payment::class, (string) $entry['target_id']) ?? self::fail('The payment is stored.');
        self::assertSame(121000, $payment->amount()->minor());
        self::assertSame(PaymentMethod::BankTransfer, $payment->method());
    }

    public function testPaymentsGoByTheirDayThenTheirId(): void
    {
        $this->proforma();
        $this->payment(430, ['DocumentType' => 3, 'DateOfPayment' => '1999-12-31T00:00:00']);
        $this->payment(431, ['DocumentType' => 3, 'DateOfPayment' => '2026-10-05T09:00:00']);
        $this->payment(432, ['DocumentType' => 3, 'DateOfPayment' => '2026-10-05']);
        $this->payment(433, ['DocumentType' => 3, 'DateOfPayment' => '2026-10-04T23:59:59']);

        $run = $this->import()->run($this->firm, ImportMode::Run, $this->payer());

        $payments = array_values(array_filter($run->report(), static fn (array $entry): bool => PaymentsStage::KEY === $entry['stage']));
        self::assertSame([430, 433, 431, 432], array_column($payments, 'external_id'), 'The time of a day does not count.');
    }

    public function testACheckWritesNothingAndASecondRunFindsEverything(): void
    {
        $this->proforma();
        $this->payment(self::PAYMENT, []);
        $this->taxDocument(self::TAX_DOCUMENT, []);
        $this->finalInvoice([]);

        $check = $this->import()->run($this->firm, ImportMode::Check, $this->payer());

        self::assertSame('created', $this->entry($check, self::PAYMENT, PaymentsStage::KEY)['outcome']);
        self::assertSame('created', $this->entry($check, self::INVOICE)['outcome']);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM payment'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM invoice'));

        $first = $this->import()->run($this->storedFirm(), ImportMode::Run, $this->payer());
        $second = $this->import()->run($this->storedFirm(), ImportMode::Run, $this->payer());

        self::assertSame(['already_linked'], array_values(array_unique(array_column(array_filter($second->report(), static fn (array $entry): bool => \in_array($entry['stage'], [DocumentsStage::KEY, PaymentsStage::KEY], true)), 'outcome'))));
        self::assertSame(['already_linked' => 1], $second->counts()['payments'], 'A linked payment is reported once and imported no more.');
        $this->em->getConnection()->executeStatement("DELETE FROM idoklad_link WHERE resource = 'IssuedDocumentPayments'");
        $third = $this->import()->run($this->storedFirm(), ImportMode::Run, $this->payer());
        self::assertSame('matched', $this->entry($third, self::PAYMENT, PaymentsStage::KEY)['outcome'], 'An imported payment is linked again.');
        self::assertSame($this->entry($first, self::PAYMENT, PaymentsStage::KEY)['target_id'], $this->entry($third, self::PAYMENT, PaymentsStage::KEY)['target_id']);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM payment'));
        self::assertSame(3, $this->rows('SELECT COUNT(*) FROM invoice'));
    }

    public function testUndoTakesThePaymentsAndTaxDocumentsAlong(): void
    {
        $this->proforma();
        $this->payment(self::PAYMENT, []);
        $this->taxDocument(self::TAX_DOCUMENT, []);
        $this->finalInvoice([]);
        $run = $this->import()->run($this->firm, ImportMode::Run, $this->payer());

        self::assertSame([], self::getContainer()->get(UndoImportedDocuments::class)->undo($run->id()));

        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM invoice'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM payment'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM payment_allocation'));
    }

    /** A proforma of 1 000 CZK without VAT at 21 %. */
    private function proforma(): void
    {
        $this->record(IdokladResource::ProformaInvoices, self::PROFORMA, $this->document([
            'Id' => self::PROFORMA,
            'DocumentNumber' => 'Z20260003',
            'VariableSymbol' => '20260003',
            'Items' => [$this->item('Deposit', 1, 1000.0, 1, 1, 21.0, 1000.0, 210.0)],
            'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 210.0, 'TotalWithVat' => 1210.0],
        ], ['DateOfTaxing']));
    }

    /**
     * The proforma paid whole on 5 October, unless the fields say otherwise.
     *
     * @param array<string, mixed> $fields
     */
    private function payment(int $id, array $fields): void
    {
        $this->record(IdokladResource::IssuedDocumentPayments, $id, $fields + [
            'Id' => $id,
            'InvoiceId' => self::PROFORMA,
            'InvoiceDocumentNumber' => 'Z20260003',
            'DocumentType' => 1,
            'DateOfPayment' => '2026-10-05T00:00:00',
            'CurrencyId' => self::CZK,
            'ExchangeRate' => 1.0,
            'ExchangeRateAmount' => 1.0,
            'PaymentOptionId' => self::TRANSFER,
            'Prices' => ['PaymentAmount' => 1210.0, 'PaymentAmountHc' => 1210.0],
            'IssuedTaxDocumentId' => self::TAX_DOCUMENT,
            'Partner' => 'Example Trading s.r.o.',
        ]);
    }

    /**
     * The tax document on the payment, unless the fields say otherwise.
     *
     * @param array<string, mixed> $fields
     */
    private function taxDocument(int $id, array $fields): void
    {
        $document = $this->document([]);
        $this->record(IdokladResource::IssuedTaxDocuments, $id, $fields + [
            'Id' => $id,
            'DocumentNumber' => 'D20260001',
            'DocumentSerialNumber' => 1,
            'DateOfIssue' => '2026-10-05T00:00:00',
            'DateOfTaxing' => '2026-10-05T00:00:00',
            'PaymentId' => self::PAYMENT,
            'ProformaInvoiceId' => self::PROFORMA,
            'CurrencyId' => self::CZK,
            'ExchangeRate' => 1.0,
            'ExchangeRateAmount' => 1.0,
            'PartnerId' => self::CUSTOMER,
            'PartnerAddress' => $document['PartnerAddress'],
            'MyAddress' => $document['MyAddress'],
            'VariableSymbol' => '20260003',
            'ReportLanguage' => 1,
            'IsSentToPartner' => 0,
            'Exported' => 0,
            'Items' => [
                ['Id' => 1, 'Name' => 'Deposit', 'PriceType' => 0, 'VatRateType' => 1, 'VatRate' => 21.0, 'Prices' => ['TotalWithoutVat' => 500.0, 'TotalVat' => 105.0, 'TotalWithVat' => 605.0]],
                ['Id' => 2, 'Name' => 'Deposit', 'PriceType' => 1, 'VatRateType' => 1, 'VatRate' => 21.0, 'Prices' => ['TotalWithoutVat' => 500.0, 'TotalVat' => 105.0, 'TotalWithVat' => 605.0]],
            ],
            'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 210.0, 'TotalWithVat' => 1210.0],
        ]);
    }

    /**
     * The invoice of 2 hours at 1 000 CZK that deducts the proforma, unless the fields say otherwise.
     *
     * @param array<string, mixed> $fields
     */
    private function finalInvoice(array $fields, int $id = self::INVOICE): void
    {
        $this->record(IdokladResource::IssuedInvoices, $id, $this->document($fields + [
            'Id' => $id,
            'DateOfIssue' => '2026-10-20',
            'DateOfTaxing' => '2026-10-20',
            'DateOfMaturity' => '2026-11-03',
            'ProformaInvoices' => [self::PROFORMA],
            'Items' => [
                $this->item('Consulting', 2, 1000.0, 1, 1, 21.0, 2000.0, 420.0),
                ['Id' => 9, 'Name' => 'Deduction of Z20260003', 'Amount' => -1.0, 'ItemType' => 2, 'InvoiceProformaId' => self::PROFORMA, 'PriceType' => 0, 'VatRateType' => 1, 'VatRate' => 21.0, 'Prices' => ['UnitPrice' => 1210.0, 'TotalWithoutVat' => -1000.0, 'TotalVat' => -210.0, 'TotalWithVat' => -1210.0]],
            ],
            'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 210.0, 'TotalWithVat' => 1210.0],
        ]));
    }

    private function import(): RunIdokladImport
    {
        return self::getContainer()->get('test.idoklad_import');
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
    private function entry(IdokladImportRun $run, int $id, string $stage = DocumentsStage::KEY): array
    {
        foreach ($run->report() as $entry) {
            if ($stage === $entry['stage'] && $id === $entry['external_id']) {
                return $entry;
            }
        }

        self::fail(\sprintf('The report has no %s %d.', $stage, $id));
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

    /** The firm of these tests is a VAT payer all along; its snapshot has no issued invoice with VAT to say so. */
    private function payer(): ImportOptions
    {
        return new ImportOptions(new \DateTimeImmutable('2020-01-01'));
    }
}
