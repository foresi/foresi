<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Idoklad\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Idoklad\Application\Import\RunIdokladImport;
use Foresi\Idoklad\Domain\IdokladPull;
use Foresi\Idoklad\Domain\IdokladRecord;
use Foresi\Idoklad\Domain\IdokladResource;
use Foresi\Idoklad\Domain\ImportMode;
use Foresi\Idoklad\Domain\ImportOptions;
use Foresi\Invoicing\Application\UndoImportedDocuments;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Payment;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Payments, tax documents on payment and invoices that deduct advances of a
 * snapshot as built after the list models of the iDoklad API v3 documentation:
 * a proforma of 1 210 CZK paid on 5 October, its tax document, and the invoice
 * of 2 420 CZK that deducts it. Importing them needs the invoicing rules of
 * the firm's country, so those tests are in Integration/Country.
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

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->em->persist(new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', '12345679'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
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
        $pull = new IdokladPull(new \DateTimeImmutable('2026-11-06 09:00:00'));
        $pull->finish(new \DateTimeImmutable('2026-11-06 09:05:00'), 3);
        $this->em->persist($pull);
        $this->em->flush();
    }

    public function testUndoTakesThePaymentsAndTaxDocumentsAlong(): void
    {
        $this->proforma();
        $this->payment(self::PAYMENT, []);
        $this->taxDocument(self::TAX_DOCUMENT, []);
        $this->finalInvoice([]);
        $run = $this->import()->run(ImportMode::Run, $this->payer());

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
        $this->em->persist(new IdokladRecord($resource, $id, $payload, new \DateTimeImmutable('2026-11-06 09:01:00')));
        $this->em->flush();
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
