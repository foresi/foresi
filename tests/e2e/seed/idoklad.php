<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Idoklad\Domain\IdokladConnection;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;

// The tenant of the iDoklad spec: a payer firm with stored iDoklad keys and a finished pull whose
// records are inserted as a pull stores them, so the e2e server never calls iDoklad. Three
// contacts, three number series, invoice 20260008 whose totals match and 20260009 whose VAT is a
// koruna off, a credit note (refused), an advance Z20260003 of 1 210 Kč paid on 5 October with its
// tax document D20260001, and one monthly template. Nothing is checked or imported yet. The spec
// walks the world "idoklad"; its screenshots import into "idoklad-screens", which nothing else touches.
$idokladFirm = new LegalEntity('Example Move s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(12), VatStatus::Registered, 'Example register, entry C 4321');
$entityManager->persist($idokladFirm);
$firmAccount($idokladFirm);
$entityManager->persist(new IdokladConnection($idokladFirm, 'example-client-id', $mailSecrets->seal('throwaway-idoklad-secret')));
$pulledAt = new DateTimeImmutable('-1 hour');
/** @param array<string, mixed> $payload */
$idokladRecord = static function (IdokladResource $resource, int $id, array $payload) use ($entityManager, $idokladFirm, $pulledAt): void {
    $entityManager->persist(new IdokladRecord($idokladFirm, $resource, $id, ['Id' => $id] + $payload, $pulledAt));
};
$myAddress = ['NickName' => 'Example Move s.r.o.', 'IdentificationNumber' => '12345695', 'VatIdentificationNumber' => 'CZ12345695', 'Email' => 'office@example.com', 'Street' => 'Example Street 12', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => 2, 'Iban' => 'CZ65 0800 0000 1920 0014 5399', 'AccountNumber' => '19-2000145399', 'BankCode' => '0800'];
$buyerAddress = ['NickName' => 'Example Trading s.r.o.', 'IdentificationNumber' => '23456787', 'VatIdentificationNumber' => 'CZ23456787', 'Email' => 'trading@example.com', 'Street' => 'Example Street 13', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => 2];
$idokladItem = static fn (string $name, float $amount, float $unitPrice, float $net, float $vat): array => ['Id' => crc32($name), 'Name' => $name, 'Amount' => $amount, 'Unit' => 'h', 'ItemType' => 0, 'PriceType' => 1, 'VatRateType' => 1, 'VatRate' => 21.0, 'DiscountPercentage' => 0.0, 'Prices' => ['UnitPrice' => $unitPrice, 'TotalWithoutVat' => $net, 'TotalVat' => $vat, 'TotalWithVat' => $net + $vat]];
/** @param array<string, mixed> $fields */
$idokladDocument = static fn (array $fields): array => $fields + [
    'DocumentNumber' => '20260008',
    'DocumentSerialNumber' => 8,
    'VariableSymbol' => '20260008',
    'ConstantSymbolId' => 7,
    'CurrencyId' => 1,
    'ExchangeRate' => 1.0,
    'ExchangeRateAmount' => 1.0,
    'DateOfIssue' => '2026-10-01',
    'DateOfTaxing' => '2026-10-01',
    'DateOfMaturity' => '2026-10-15',
    'Description' => 'Consulting',
    'OrderNumber' => '',
    'ItemsTextPrefix' => '',
    'ItemsTextSuffix' => '',
    'PartnerId' => 501,
    'PartnerAddress' => $buyerAddress,
    'MyAddress' => $myAddress,
    'PaymentOptionId' => 1,
    'ReportLanguage' => 1,
    'VatRegime' => 1,
    'IsSentToPurchaser' => 0,
    'Exported' => 0,
    'ProformaInvoices' => [],
    'Items' => [$idokladItem('Consulting', 2.0, 1000.0, 2000.0, 420.0)],
    'Prices' => ['TotalWithoutVat' => 2000.0, 'TotalVat' => 420.0, 'TotalWithVat' => 2420.0, 'TotalWithoutVatHc' => 2000.0, 'TotalVatHc' => 420.0, 'TotalWithVatHc' => 2420.0],
    'Metadata' => ['DateCreated' => '2026-10-01T09:00:00.123', 'DateLastChange' => '2026-10-02T10:39:22.873'],
];
$idokladRecord(IdokladResource::Countries, 2, ['Code' => 'CZE', 'Name' => 'Česká republika', 'NameEnglish' => 'Czech Republic', 'CurrencyId' => 1]);
$idokladRecord(IdokladResource::Currencies, 1, ['Code' => 'CZK', 'Symbol' => 'Kč']);
$idokladRecord(IdokladResource::PaymentOptions, 1, ['Code' => 'B', 'Name' => 'Převodem', 'IsDefault' => true]);
$idokladRecord(IdokladResource::ConstantSymbols, 7, ['Code' => '0308', 'Name' => 'Služby', 'CountryId' => 2]);
$idokladRecord(IdokladResource::BankAccounts, 1, ['Iban' => 'CZ65 0800 0000 1920 0014 5399']);
$idokladRecord(IdokladResource::Contacts, 501, ['CompanyName' => 'Example Trading s.r.o.', 'IdentificationNumber' => '23456787', 'VatIdentificationNumber' => 'CZ23456787', 'Email' => 'trading@example.com', 'Street' => 'Example Street 13', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => 2]);
$idokladRecord(IdokladResource::Contacts, 502, ['CompanyName' => 'Example Retail a.s.', 'IdentificationNumber' => '34567895', 'Email' => 'retail@example.com', 'Street' => 'Example Street 14', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => 2]);
$idokladRecord(IdokladResource::Contacts, 503, ['Firstname' => 'Robin', 'Surname' => 'Example', 'Email' => 'robin@example.com', 'Street' => 'Example Street 15', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => 2]);
$idokladRecord(IdokladResource::NumericSequences, 1, ['DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 9, 'IsDefault' => true]);
$idokladRecord(IdokladResource::NumericSequences, 3, ['DocumentType' => 1, 'Name' => 'Zálohy', 'NumberFormat' => 'ZRRRRNNNN', 'Year' => 2026, 'LastNumber' => 3, 'IsDefault' => true]);
$idokladRecord(IdokladResource::NumericSequences, 4, ['DocumentType' => 10, 'Name' => 'Daňové doklady', 'NumberFormat' => 'DRRRRNNNN', 'Year' => 2026, 'LastNumber' => 1, 'IsDefault' => true]);
$idokladRecord(IdokladResource::IssuedInvoices, 101, $idokladDocument([]));
$idokladRecord(IdokladResource::IssuedInvoices, 102, $idokladDocument(['DocumentNumber' => '20260009', 'DocumentSerialNumber' => 9, 'VariableSymbol' => '20260009', 'DateOfIssue' => '2026-10-10', 'DateOfTaxing' => '2026-10-10', 'DateOfMaturity' => '2026-10-24', 'Prices' => ['TotalWithoutVat' => 2000.0, 'TotalVat' => 421.0, 'TotalWithVat' => 2421.0]]));
$idokladRecord(IdokladResource::CreditNotes, 301, ['DocumentNumber' => 'O20260001', 'DateOfIssue' => '2026-10-12']);
// An advance has no tax point of its own; its tax document has.
$idokladRecord(IdokladResource::ProformaInvoices, 201, array_diff_key($idokladDocument([
    'DocumentNumber' => 'Z20260003',
    'DocumentSerialNumber' => 3,
    'VariableSymbol' => '20260003',
    'Description' => 'Deposit',
    'Items' => [$idokladItem('Deposit', 1.0, 1000.0, 1000.0, 210.0)],
    'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 210.0, 'TotalWithVat' => 1210.0],
]), ['DateOfTaxing' => true]));
$idokladRecord(IdokladResource::IssuedDocumentPayments, 401, ['InvoiceId' => 201, 'InvoiceDocumentNumber' => 'Z20260003', 'DocumentType' => 1, 'DateOfPayment' => '2026-10-05T00:00:00', 'CurrencyId' => 1, 'ExchangeRate' => 1.0, 'ExchangeRateAmount' => 1.0, 'PaymentOptionId' => 1, 'Prices' => ['PaymentAmount' => 1210.0, 'PaymentAmountHc' => 1210.0], 'IssuedTaxDocumentId' => 601, 'Partner' => 'Example Trading s.r.o.']);
$idokladRecord(IdokladResource::IssuedTaxDocuments, 601, [
    'DocumentNumber' => 'D20260001',
    'DocumentSerialNumber' => 1,
    'DateOfIssue' => '2026-10-05T00:00:00',
    'DateOfTaxing' => '2026-10-05T00:00:00',
    'PaymentId' => 401,
    'ProformaInvoiceId' => 201,
    'CurrencyId' => 1,
    'ExchangeRate' => 1.0,
    'ExchangeRateAmount' => 1.0,
    'PartnerId' => 501,
    'PartnerAddress' => $buyerAddress,
    'MyAddress' => $myAddress,
    'VariableSymbol' => '20260003',
    'ReportLanguage' => 1,
    'IsSentToPartner' => 0,
    'Exported' => 0,
    'Items' => [['Id' => 1, 'Name' => 'Deposit', 'PriceType' => 0, 'VatRateType' => 1, 'VatRate' => 21.0, 'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 210.0, 'TotalWithVat' => 1210.0]]],
    'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 210.0, 'TotalWithVat' => 1210.0],
]);
$idokladRecord(IdokladResource::RecurringInvoices, 901, [
    'InvoiceTemplate' => [
        'DocumentType' => 0,
        'CurrencyId' => 1,
        'Partner' => ['Id' => 502, 'CompanyName' => 'Example Retail a.s.'],
        'NumericSequenceId' => 1,
        'PaymentOptionId' => 1,
        'BankAccountId' => 1,
        'ConstantSymbolId' => 7,
        'OrderNumber' => '',
        'Description' => 'Hosting {month/year}',
        'ItemsTextPrefix' => '',
        'ItemsTextSuffix' => '',
        'ReportLanguage' => 1,
        'TaxingType' => 1,
        'InvoiceMaturity' => 14,
        'DiscountPercentage' => 0.0,
        'VatReverseChargeCodeId' => null,
        'Note' => '',
        'Items' => [['Id' => 1, 'ItemType' => 0, 'Name' => 'Hosting', 'Amount' => 1.0, 'Unit' => 'ks', 'PriceType' => 1, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 1000.0]]],
    ],
    'RecurringSetting' => [
        'TemplateName' => 'Monthly hosting',
        'RecurrenceType' => 2,
        'RecurrenceCount' => 1,
        'IssueLastDayOfMonth' => false,
        'DateOfStart' => '2026-01-01T00:00:00',
        'TypeOfEnd' => 0,
        'DateOfEnd' => null,
        'CopyCountEnd' => 0,
        'CopyCountActual' => 10,
        'DateOfLastIssue' => '2026-10-01T00:00:00',
        'DateOfNextIssue' => '2026-11-01T00:00:00',
        'IsActive' => true,
        'SendToPurchaser' => true,
        'SendToSupplier' => false,
        'SendToOtherEmails' => false,
        'SendToAccountant' => false,
    ],
]);
$idokladPull = new IdokladPull($idokladFirm, $pulledAt->modify('-5 minutes'));
foreach ([[IdokladResource::Contacts, 3], [IdokladResource::NumericSequences, 3], [IdokladResource::IssuedInvoices, 2], [IdokladResource::ProformaInvoices, 1], [IdokladResource::IssuedTaxDocuments, 1], [IdokladResource::CreditNotes, 1], [IdokladResource::RecurringInvoices, 1], [IdokladResource::IssuedDocumentPayments, 1]] as [$resource, $records]) {
    $idokladPull->counted($resource, $records);
}
$idokladPull->noteChange('2026-10-02T10:39:22.873');
$idokladPull->finish($pulledAt, 42);
$entityManager->persist($idokladPull);
$entityManager->flush();
