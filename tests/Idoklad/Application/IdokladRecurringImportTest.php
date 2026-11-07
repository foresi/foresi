<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Application;

use App\Idoklad\Application\Import\RecurringStage;
use App\Idoklad\Application\Import\RunIdokladImport;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladLink;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportMode;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Party\Support\FirmAccounts;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The recurring stage on 6 November 2026, with a customer and a series that
 * earlier stages linked, and iDoklad's code lists in the snapshot.
 */
final class IdokladRecurringImportTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string IBAN = 'CZ6508000000192000145399';
    private const string SECOND_IBAN = 'CZ5508000000001234567899';
    private const int PARTNER = 501;
    private const int SEQUENCE = 11;
    private const int CZK = 1;
    private const int EUR = 2;
    private const int TRANSFER = 1;
    private const int CASH = 2;
    private const int CARD = 10;
    private const int ACCOUNT = 3;
    private const int SYMBOL = 7;

    private EntityManagerInterface $em;
    private LegalEntity $firm;
    private Party $customer;
    private Series $series;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-06 11:00:00');
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $container->get(TenantContext::class)->switchTo(TenantId::generate());

        $this->firm = new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em->persist($this->firm);
        FirmAccounts::add($this->em, $this->firm, self::IBAN);
        FirmAccounts::add($this->em, $this->firm, self::SECOND_IBAN);
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em->persist($this->customer);
        $this->em->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->persist(new PartyEmail($this->customer, 'billing@example.com', EmailPurpose::Invoices));
        $this->series = new Series($this->firm, 'Invoices', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->em->persist($this->series);
        $earlier = Id::generate();
        $this->em->persist(new IdokladLink($this->firm, IdokladResource::Contacts, self::PARTNER, $this->customer->id(), $earlier, new \DateTimeImmutable('2026-11-06 09:00:00')));
        $this->em->persist(new IdokladLink($this->firm, IdokladResource::NumericSequences, self::SEQUENCE, $this->series->id(), $earlier, new \DateTimeImmutable('2026-11-06 09:00:00')));

        $this->record(IdokladResource::Currencies, self::CZK, ['Id' => self::CZK, 'Code' => 'CZK']);
        $this->record(IdokladResource::Currencies, self::EUR, ['Id' => self::EUR, 'Code' => 'EUR']);
        $this->record(IdokladResource::PaymentOptions, self::TRANSFER, ['Id' => self::TRANSFER, 'Name' => 'Převodem']);
        $this->record(IdokladResource::PaymentOptions, self::CASH, ['Id' => self::CASH, 'Name' => 'Hotově']);
        $this->record(IdokladResource::PaymentOptions, 9, ['Id' => 9, 'Name' => 'Zápočtem']);
        $this->record(IdokladResource::PaymentOptions, self::CARD, ['Id' => self::CARD, 'Name' => ' PLATEBNÍ KARTOU ']);
        $this->record(IdokladResource::ConstantSymbols, self::SYMBOL, ['Id' => self::SYMBOL, 'Code' => '0308']);
        $this->record(IdokladResource::BankAccounts, self::ACCOUNT, ['Id' => self::ACCOUNT, 'Iban' => 'CZ55 0800 0000 0012 3456 7899']);
        $pull = new IdokladPull($this->firm, new \DateTimeImmutable('2026-11-06 09:00:00'));
        $pull->finish(new \DateTimeImmutable('2026-11-06 09:05:00'), 3);
        $this->em->persist($pull);
        $this->em->flush();
    }

    public function testAMonthlyTemplateIsImportedPausedWithItsScheduleDataAndHistory(): void
    {
        $this->template(901, [], [
            'Items' => [
                ['Id' => 1, 'ItemType' => 0, 'Name' => 'Hosting {month/year}', 'Amount' => 1.5, 'Unit' => 'h', 'PriceType' => 1, 'VatRateType' => 0, 'Prices' => ['UnitPrice' => 1200.5]],
                ['Id' => 2, 'ItemType' => 0, 'Name' => 'Support', 'Amount' => 2, 'Unit' => 'ks', 'PriceType' => 0, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 100]],
            ],
            'DiscountPercentage' => 5.5,
        ]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertNull($run->error());
        $entry = $this->entry($run, 901);
        self::assertSame([], $entry['reasons']);
        self::assertSame('created', $entry['outcome']);
        self::assertSame('Monthly hosting', $entry['values']['name']);
        self::assertSame('2026-11-01', $entry['values']['next_issue']);
        self::assertSame('2026-11-01', $entry['values']['idoklad_next_issue']);
        self::assertSame('2026-11-01,2026-12-01,2027-01-01,2027-02-01,2027-03-01', $entry['values']['next_issues']);
        self::assertSame(10, $entry['values']['issued_count']);
        self::assertSame('paused', $entry['values']['state']);

        $row = $this->templateRow((string) $entry['target_id']);
        self::assertSame('paused', $row['state']);
        self::assertSame('901', $row['imported_from']);
        self::assertSame('Monthly hosting', $row['name']);
        self::assertSame('2026-01-01', $row['first_issue_on']);
        self::assertSame('month', $row['recurrence_unit']);
        self::assertSame(1, $row['recurrence_every']);
        self::assertFalse((bool) $row['on_last_day_of_month']);
        self::assertSame('never', $row['recurrence_end']);
        self::assertSame(10, $row['issued_count']);
        self::assertSame('2026-10-01', $row['last_occurrence']);
        self::assertSame('2026-11-01', $row['next_occurrence']);
        self::assertSame('last_day_of_month', $row['tax_point']);
        self::assertSame(14, $row['due_days']);
        self::assertSame($this->customer->id(), $row['customer_id']);
        self::assertSame($this->series->id(), $row['series_id']);
        self::assertSame('bank_transfer', $row['payment_method']);
        self::assertSame(self::SECOND_IBAN, $row['bank_account'], 'The account iDoklad names, not the primary one.');
        self::assertSame('0308', $row['constant_symbol']);
        self::assertSame('PO-{month}', $row['order_number']);
        self::assertSame('Hosting {month/year}', $row['description']);
        self::assertSame('Before {quarter}', $row['text_before']);
        self::assertSame('Number {document_number}, due {day+14}.{month}.{year}', $row['text_after']);
        self::assertSame('en', $row['language']);
        self::assertSame('send', $row['delivery']);
        self::assertTrue((bool) $row['copy_to_me']);
        self::assertTrue((bool) $row['copy_to_others']);

        $lines = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM recurring_invoice_line WHERE recurring_invoice_id = :id ORDER BY position', ['id' => $entry['target_id']]);
        self::assertCount(2, $lines);
        self::assertSame('Hosting {month/year}', $lines[0]['text']);
        self::assertSame(15000, $lines[0]['quantity']);
        self::assertSame('h', $lines[0]['unit']);
        self::assertSame(120050, $lines[0]['price_minor']);
        self::assertSame('without_vat', $lines[0]['price_type']);
        self::assertSame('standard', $lines[0]['treatment']);
        self::assertSame('reduced', $lines[0]['rate_kind']);
        self::assertSame('5.50', $lines[0]['discount']);
        self::assertSame('with_vat', $lines[1]['price_type']);
        self::assertSame('base', $lines[1]['rate_kind']);
        self::assertSame(10000, $lines[1]['price_minor']);

        $link = $this->link(901);
        self::assertSame($entry['target_id'], $link?->targetId());
        self::assertSame($run->id(), $link?->runId());
    }

    public function testYearlyStaysYearlyAndEveryTwelveMonthsStaysMonthly(): void
    {
        $this->template(902, ['RecurrenceType' => 3, 'RecurrenceCount' => 1, 'DateOfStart' => '2025-03-15T00:00:00', 'CopyCountActual' => 2, 'DateOfLastIssue' => '2026-03-15T00:00:00', 'DateOfNextIssue' => '2027-03-15T00:00:00']);
        $this->template(903, ['RecurrenceType' => 2, 'RecurrenceCount' => 12, 'DateOfStart' => '2025-03-15T00:00:00', 'CopyCountActual' => 2, 'DateOfLastIssue' => '2026-03-15T00:00:00', 'DateOfNextIssue' => '2027-03-15T00:00:00']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $yearly = $this->templateRow((string) $this->entry($run, 902)['target_id']);
        self::assertSame(['year', 1, '2027-03-15'], [$yearly['recurrence_unit'], $yearly['recurrence_every'], $yearly['next_occurrence']]);
        $monthly = $this->templateRow((string) $this->entry($run, 903)['target_id']);
        self::assertSame(['month', 12, '2027-03-15'], [$monthly['recurrence_unit'], $monthly['recurrence_every'], $monthly['next_occurrence']]);
        self::assertSame('2027-03-15,2028-03-15,2029-03-15,2030-03-15,2031-03-15', $this->entry($run, 903)['values']['next_issues']);
    }

    public function testTheLastDayOfTheMonthIsKept(): void
    {
        $this->template(904, ['IssueLastDayOfMonth' => true, 'DateOfStart' => '2026-01-31', 'DateOfLastIssue' => '2026-10-31', 'DateOfNextIssue' => '2026-11-30']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $row = $this->templateRow((string) $this->entry($run, 904)['target_id']);
        self::assertTrue((bool) $row['on_last_day_of_month']);
        self::assertSame('2026-11-30', $row['next_occurrence']);
        self::assertStringStartsWith('2026-11-30,2026-12-31,2027-01-31,2027-02-28', (string) $this->entry($run, 904)['values']['next_issues']);
    }

    public function testAnEndOnADateAndAnEndAfterACountGoOnFromTheCarriedCount(): void
    {
        $this->template(905, ['TypeOfEnd' => 1, 'DateOfEnd' => '2027-01-15']);
        $this->template(906, ['TypeOfEnd' => 2, 'CopyCountEnd' => 12]);
        $this->template(907, ['TypeOfEnd' => 2, 'CopyCountEnd' => 10, 'IsActive' => false, 'DateOfNextIssue' => null]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $onDate = $this->templateRow((string) $this->entry($run, 905)['target_id']);
        self::assertSame(['on_date', '2027-01-15'], [$onDate['recurrence_end'], $onDate['end_on']]);
        self::assertSame('2026-11-01,2026-12-01,2027-01-01', $this->entry($run, 905)['values']['next_issues']);
        $afterCount = $this->templateRow((string) $this->entry($run, 906)['target_id']);
        self::assertSame(['after_count', 12, 10, 'paused'], [$afterCount['recurrence_end'], $afterCount['end_after'], $afterCount['issued_count'], $afterCount['state']]);
        self::assertSame('2026-11-01,2026-12-01', $this->entry($run, 906)['values']['next_issues'], 'Two of twelve are left.');
        $ended = $this->entry($run, 907);
        self::assertSame('created', $ended['outcome']);
        self::assertSame('ended', $ended['values']['state']);
        self::assertNull($ended['values']['next_issue']);
        self::assertArrayNotHasKey('next_issues', $ended['values']);
        self::assertNull($this->templateRow((string) $ended['target_id'])['next_occurrence']);
    }

    public function testANextIssueOtherThanIdokladsIsRefusedWithBothDates(): void
    {
        $this->template(908, ['DateOfNextIssue' => '2026-11-15T00:00:00']);
        $this->template(924);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame('created', $this->entry($run, 924)['outcome'], 'The refusal stops only its own template.');
        $entry = $this->entry($run, 908);
        self::assertSame('refused', $entry['outcome']);
        self::assertSame([RecurringStage::NEXT_ISSUE_MISMATCH], $entry['reasons']);
        self::assertSame('2026-11-01', $entry['values']['next_issue']);
        self::assertSame('2026-11-15', $entry['values']['idoklad_next_issue']);
        self::assertSame(1, $this->templates());
        self::assertNull($this->link(908));
    }

    public function testAnUnknownVariableIsRefusedWhereverItIs(): void
    {
        $this->template(909, [], ['ItemsTextSuffix' => 'Thanks {customer_name}']);
        $this->template(910, [], ['Items' => [['ItemType' => 0, 'Name' => 'Hosting {MONTH} {week+1}', 'Amount' => 1, 'PriceType' => 1, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 100]]]]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame([RecurringStage::UNKNOWN_VARIABLE], $this->entry($run, 909)['reasons']);
        self::assertSame('{customer_name}', $this->entry($run, 909)['values']['unknown_variables']);
        self::assertSame('{MONTH} {week+1}', $this->entry($run, 910)['values']['unknown_variables']);
        self::assertSame(0, $this->templates());
    }

    public function testOffsetVariablesWithSpacesAroundTheSignAreForesisTokens(): void
    {
        $this->template(960, [], [
            'OrderNumber' => 'PO-{month - 1}',
            'Description' => 'Hosting {month/year +1}',
            'ItemsTextPrefix' => 'Q{quarter+ 2}',
            'ItemsTextSuffix' => 'Due {day + 14}.{month}.{year + 1}',
            'Items' => [['ItemType' => 0, 'Name' => 'Hosting {year +1} {month/year}', 'Amount' => 1, 'PriceType' => 1, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 100]]],
        ]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, 960);
        self::assertSame('created', $entry['outcome']);
        $row = $this->em->getConnection()->fetchAssociative('SELECT order_number, description, text_before, text_after FROM recurring_invoice WHERE id = :id', ['id' => $entry['target_id']]);
        self::assertSame(['order_number' => 'PO-{month-1}', 'description' => 'Hosting {month/year+1}', 'text_before' => 'Q{quarter+2}', 'text_after' => 'Due {day+14}.{month}.{year+1}'], $row);
        self::assertSame('Hosting {year+1} {month/year}', $this->em->getConnection()->fetchOne('SELECT text FROM recurring_invoice_line WHERE recurring_invoice_id = :id', ['id' => $entry['target_id']]));
    }

    public function testDeliveryReviewWithoutCopiesAndTheAccountantIsANote(): void
    {
        $this->template(911, ['SendToPurchaser' => false, 'SendToSupplier' => false, 'SendToOtherEmails' => false, 'SendToAccountant' => true], ['Note' => 'Internal', 'IsConstantVariableSymbol' => true]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, 911);
        self::assertSame('created', $entry['outcome']);
        self::assertSame([RecurringStage::NOTE_NOT_CARRIED, RecurringStage::FIXED_VARIABLE_SYMBOL, RecurringStage::ACCOUNTANT_NOT_SENT], $entry['reasons']);
        $row = $this->templateRow((string) $entry['target_id']);
        self::assertSame(['review', false, false], [$row['delivery'], (bool) $row['copy_to_me'], (bool) $row['copy_to_others']]);
    }

    public function testCashZeroRateAndARoundingItem(): void
    {
        $this->template(912, [], [
            'PaymentOptionId' => self::CASH,
            'Items' => [
                ['ItemType' => 1, 'Name' => 'Rounding', 'Amount' => 1, 'PriceType' => 0, 'VatRateType' => 2, 'Prices' => ['UnitPrice' => 0.4]],
                ['ItemType' => 0, 'Name' => 'Export', 'Amount' => 1, 'PriceType' => 1, 'VatRateType' => 2, 'Prices' => ['UnitPrice' => 100]],
            ],
        ]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, 912);
        self::assertSame([RecurringStage::ROUNDING_ITEM_SKIPPED], $entry['reasons']);
        $row = $this->templateRow((string) $entry['target_id']);
        self::assertSame(['cash', null], [$row['payment_method'], $row['bank_account']]);
        $lines = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM recurring_invoice_line WHERE recurring_invoice_id = :id', ['id' => $entry['target_id']]);
        self::assertCount(1, $lines);
        self::assertSame(['exempt', null], [$lines[0]['treatment'], $lines[0]['rate_kind']]);
    }

    public function testAZeroRateLineIsReverseChargeForACustomerWithAVatId(): void
    {
        $german = new Party(PartyKind::Company, 'Example GmbH', null, VatId::fromString('DE123456789'), '');
        $this->em->persist($german);
        $this->em->persist(new PartyAddress($german, AddressType::Billing, new Address('Example Strasse 1', 'Example Stadt', '10115', 'DE')));
        $this->em->persist(new PartyEmail($german, 'billing@example.com', EmailPurpose::Invoices));
        $this->em->persist(new IdokladLink($this->firm, IdokladResource::Contacts, 778, $german->id(), Id::generate(), new \DateTimeImmutable('2026-11-06 09:00:00')));
        $this->record(IdokladResource::Contacts, 778, ['Id' => 778, 'CompanyName' => 'Example GmbH', 'VatIdentificationNumber' => ' DE123456789 ']);
        $zero = ['Items' => [['ItemType' => 0, 'Name' => 'Consulting', 'Amount' => 1, 'PriceType' => 1, 'VatRateType' => 2, 'Prices' => ['UnitPrice' => 100]]]];
        $this->template(961, [], ['Partner' => ['Id' => 778]] + $zero);
        $this->template(962, [], ['VatReverseChargeCodeId' => 4] + $zero);
        $blank = new Party(PartyKind::Company, 'Example Blank s.r.o.', new RegistrationNumber('CZ', '23456787'), null, '');
        $this->em->persist($blank);
        $this->em->persist(new PartyAddress($blank, AddressType::Billing, new Address('Example Street 3', 'Example City', '100 00', 'CZ')));
        $this->em->persist(new PartyEmail($blank, 'blank@example.com', EmailPurpose::Invoices));
        $this->em->persist(new IdokladLink($this->firm, IdokladResource::Contacts, 779, $blank->id(), Id::generate(), new \DateTimeImmutable('2026-11-06 09:00:00')));
        $this->record(IdokladResource::Contacts, 779, ['Id' => 779, 'CompanyName' => 'Example Blank s.r.o.', 'VatIdentificationNumber' => '  ']);
        $this->template(963, [], ['Partner' => ['Id' => 779]] + $zero);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, 961);
        self::assertSame('created', $entry['outcome'], implode(', ', $entry['reasons']));
        $line = $this->em->getConnection()->fetchAssociative('SELECT treatment, rate_kind FROM recurring_invoice_line WHERE recurring_invoice_id = :id', ['id' => $entry['target_id']]);
        self::assertIsArray($line);
        self::assertSame(['reverse_charge', null], array_values($line));
        self::assertSame('created', $this->entry($run, 962)['outcome'], 'Reverse charge as the template says; at home the supply code is optional, and the snapshot does not hold one.');
        $exempt = $this->entry($run, 963);
        self::assertSame('created', $exempt['outcome'], implode(', ', $exempt['reasons']));
        self::assertSame('exempt', $this->em->getConnection()->fetchOne('SELECT treatment FROM recurring_invoice_line WHERE recurring_invoice_id = :id', ['id' => $exempt['target_id']]), 'A VAT ID of spaces is none.');
    }

    public function testAnInactiveTemplateIsImportedPausedAndFlagged(): void
    {
        $this->template(913, ['IsActive' => false, 'DateOfNextIssue' => '2026-05-01']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, 913);
        self::assertSame('created', $entry['outcome']);
        self::assertSame([RecurringStage::INACTIVE], $entry['reasons']);
        self::assertFalse($entry['values']['is_active']);
        self::assertSame('paused', $this->templateRow((string) $entry['target_id'])['state']);
    }

    public function testAdvanceTemplatesAndOtherTypesAreRefusedAndCounted(): void
    {
        $this->template(914, [], ['DocumentType' => 1]);
        $this->template(915, [], ['DocumentType' => 1]);
        $this->template(916, [], ['DocumentType' => 5]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame([RecurringStage::ADVANCE_UNSUPPORTED], $this->entry($run, 914)['reasons']);
        self::assertSame([RecurringStage::UNKNOWN_TYPE], $this->entry($run, 916)['reasons']);
        self::assertSame(['refused' => 3], $run->counts()[RecurringStage::KEY]);
        self::assertCount(2, array_filter($run->report(), static fn (array $entry): bool => [RecurringStage::ADVANCE_UNSUPPORTED] === $entry['reasons']));
        self::assertSame(0, $this->templates());
    }

    public function testWhatForesiCannotHoldIsRefusedWithItsReasons(): void
    {
        $this->template(917, [], ['CurrencyId' => self::EUR, 'VatReverseChargeCodeId' => 4, 'ReportLanguage' => 4, 'PaymentOptionId' => 9, 'Partner' => ['Id' => 777], 'NumericSequenceId' => 99]);
        $this->template(918, ['RecurrenceType' => 7]);
        $this->template(919, [], ['Items' => [['ItemType' => 0, 'Name' => 'Odd', 'Amount' => 1.23456, 'PriceType' => 1, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 1]]]]);
        $this->template(920, [], ['InvoiceMaturity' => 400]);
        // The second reduced rate is Czech from 2015 to 2023 only, so a template issuing now has none, though it started in 2023.
        $this->template(923, ['DateOfStart' => '2023-01-01T00:00:00'], ['Items' => [['ItemType' => 0, 'Name' => 'Books', 'Amount' => 1, 'PriceType' => 1, 'VatRateType' => 3, 'Prices' => ['UnitPrice' => 100]]]]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame([
            RecurringStage::FOREIGN_CURRENCY,
            RecurringStage::CUSTOMER_NOT_LINKED,
            RecurringStage::SERIES_NOT_LINKED,
            RecurringStage::UNSUPPORTED_LANGUAGE,
            RecurringStage::UNKNOWN_PAYMENT_OPTION,
        ], $this->entry($run, 917)['reasons']);
        self::assertSame('EUR', $this->entry($run, 917)['values']['currency']);
        self::assertSame([RecurringStage::INVALID_SCHEDULE], $this->entry($run, 918)['reasons']);
        self::assertSame([RecurringStage::INVALID_ITEM], $this->entry($run, 919)['reasons']);
        self::assertSame(['recurring.problem.due_days_invalid'], $this->entry($run, 920)['reasons']);
        self::assertSame(['invoice.problem.line_rate_missing'], $this->entry($run, 923)['reasons']);
        self::assertSame(0, $this->templates());
    }

    public function testACheckWritesNothingButItsReport(): void
    {
        $this->template(921);

        $run = $this->import()->run($this->firm, ImportMode::Check);

        self::assertSame('created', $this->entry($run, 921)['outcome']);
        self::assertSame(0, $this->templates());
        self::assertNull($this->link(921));
    }

    public function testASecondRunFindsTheLink(): void
    {
        $this->template(922);
        $first = $this->import()->run($this->firm, ImportMode::Run);
        $this->template(925);

        $second = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($second, 922);
        self::assertSame('already_linked', $entry['outcome']);
        self::assertSame($this->entry($first, 922)['target_id'], $entry['target_id']);
        self::assertSame('created', $this->entry($second, 925)['outcome'], 'A linked template stops only itself.');
        self::assertSame(2, $this->templates());
    }

    public function testTheCustomerTheAccountTheOptionAndTheNameAsIdokladGivesThem(): void
    {
        $name = str_repeat('Ž', 130);
        $this->template(930, ['TemplateName' => '  '.$name], ['Partner' => ['Id' => self::PARTNER], 'PartnerId' => 777, 'BankAccountId' => null, 'Note' => "  \n", 'InvoiceMaturity' => null]);
        $this->template(931, [], ['PaymentOptionId' => self::CARD]);
        $this->template(932, [], ['PaymentOptionId' => 99]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, 930);
        self::assertSame([], $entry['reasons'], 'The partner of the template wins and a blank note is no note.');
        $row = $this->templateRow((string) $entry['target_id']);
        self::assertSame(self::IBAN, $row['bank_account'], 'Without an iDoklad account, the primary one.');
        self::assertSame(mb_substr($name, 0, 120), $row['name']);
        self::assertSame(0, $row['due_days']);
        self::assertSame('card', $this->templateRow((string) $this->entry($run, 931)['target_id'])['payment_method']);
        self::assertSame([RecurringStage::UNKNOWN_PAYMENT_OPTION], $this->entry($run, 932)['reasons']);
    }

    public function testATemplateThatIssuedNothingYetStartsFromNone(): void
    {
        $this->template(933, ['DateOfStart' => '2026-12-01', 'CopyCountActual' => null, 'DateOfLastIssue' => null, 'DateOfNextIssue' => '2026-12-01']);
        $this->template(934, ['DateOfStart' => '2026-12-01', 'CopyCountActual' => -3, 'DateOfLastIssue' => null, 'DateOfNextIssue' => '2026-12-01']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame(0, $this->entry($run, 933)['values']['issued_count']);
        self::assertSame(0, $this->entry($run, 934)['values']['issued_count']);
    }

    public function testASchedulePartIdokladDoesNotNameIsRefused(): void
    {
        $this->template(935, ['TypeOfEnd' => 9]);
        $this->template(936, ['DateOfStart' => 'x2026-01-01']);
        $this->template(937, ['DateOfStart' => '2026-02-30T00:00:00']);
        $this->template(938, [], ['TaxingType' => null]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        foreach ([935, 936, 937, 938] as $id) {
            self::assertSame([RecurringStage::INVALID_SCHEDULE], $this->entry($run, $id)['reasons'], (string) $id);
        }
    }

    public function testTheDecimalsOfAnItemAreTakenUpToTheirScale(): void
    {
        $this->template(940, [], ['DiscountPercentage' => 5.55, 'Items' => [$this->item(['Amount' => 1.2345, 'Prices' => ['UnitPrice' => 1200.55]])]]);
        $this->template(941, [], ['DiscountPercentage' => 5.00004, 'Items' => [$this->item(['Amount' => 1.0000004])]]);
        $this->template(942, [], ['DiscountPercentage' => null]);
        $this->template(943, [], ['Items' => [$this->item(['Amount' => 1.234504])]]);
        $this->template(944, [], ['Items' => [$this->item(['Prices' => ['UnitPrice' => 100.005]])]]);
        $this->template(945, [], ['Items' => [$this->item(['PriceType' => 9])]]);
        $this->template(946, [], ['Items' => [$this->item(['VatRateType' => null])]]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame([[12345, 120055, '5.55']], $this->lineFigures($run, 940));
        self::assertSame([[10000, 100000, '5.00']], $this->lineFigures($run, 941), 'What a float adds past the scale is noise.');
        self::assertSame([[10000, 100000, '0.00']], $this->lineFigures($run, 942));
        foreach ([943, 944, 945, 946] as $id) {
            self::assertSame([RecurringStage::INVALID_ITEM], $this->entry($run, $id)['reasons'], (string) $id);
        }
    }

    public function testEachReasonIsGivenOnceAndEveryItemIsChecked(): void
    {
        $odd = $this->item(['Amount' => 1.23456]);
        $this->template(947, [], ['Items' => ['junk', $odd, $odd, $this->item(['Name' => 'Fee {foo} {foo}'])]]);
        $books = $this->item(['VatRateType' => 3]);
        $this->template(948, [], ['Items' => [$books, $books, $this->item(['Unit' => str_repeat('u', 40)])]]);
        $this->template(949, [], ['Items' => ['junk', $this->item()]]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame([RecurringStage::INVALID_ITEM, RecurringStage::UNKNOWN_VARIABLE], $this->entry($run, 947)['reasons']);
        self::assertSame('{foo}', $this->entry($run, 947)['values']['unknown_variables']);
        self::assertSame(['invoice.problem.line_rate_missing', 'invoice.problem.too_long'], $this->entry($run, 948)['reasons']);
        self::assertSame('created', $this->entry($run, 949)['outcome'], 'An item that is no object is left out.');
    }

    public function testAnEndedTemplateIsCheckedOnItsLastIssue(): void
    {
        $books = ['Items' => [$this->item(['VatRateType' => 3])]];
        $this->template(950, ['DateOfStart' => '2022-01-01', 'TypeOfEnd' => 2, 'CopyCountEnd' => 10, 'DateOfLastIssue' => '2022-10-01', 'IsActive' => false, 'DateOfNextIssue' => null], $books);
        $this->template(951, ['TypeOfEnd' => 2, 'CopyCountEnd' => 10, 'IsActive' => false, 'DateOfNextIssue' => null], $books);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame('ended', $this->entry($run, 950)['values']['state'], 'The second reduced rate held in 2022.');
        self::assertSame(['invoice.problem.line_rate_missing'], $this->entry($run, 951)['reasons']);
    }

    public function testANonPayerTakesItemsWithoutVat(): void
    {
        $this->firm->update('Example s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, 'Example register, entry C 1');
        $this->em->flush();
        $this->template(952);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, 952);
        self::assertSame('created', $entry['outcome']);
        $line = $this->em->getConnection()->fetchAssociative('SELECT * FROM recurring_invoice_line WHERE recurring_invoice_id = :id', ['id' => $entry['target_id']]);
        self::assertSame(['not_applicable', null], [$line['treatment'] ?? '', $line['rate_kind'] ?? null]);
    }

    private function import(): RunIdokladImport
    {
        return self::getContainer()->get('test.idoklad_import');
    }

    /**
     * A monthly invoice template since January that issued ten invoices, the
     * last on 1 October, and sends to the customer with both copies.
     *
     * @param array<string, mixed> $setting
     * @param array<string, mixed> $template
     */
    private function template(int $id, array $setting = [], array $template = []): void
    {
        $this->record(IdokladResource::RecurringInvoices, $id, [
            'Id' => $id,
            'InvoiceTemplate' => $template + [
                'DocumentType' => 0,
                'CurrencyId' => self::CZK,
                'Partner' => ['Id' => self::PARTNER, 'CompanyName' => 'Example Customer a.s.'],
                'NumericSequenceId' => self::SEQUENCE,
                'PaymentOptionId' => self::TRANSFER,
                'BankAccountId' => self::ACCOUNT,
                'ConstantSymbolId' => self::SYMBOL,
                'OrderNumber' => 'PO-{month}',
                'Description' => 'Hosting {month/year}',
                'ItemsTextPrefix' => 'Before {quarter}',
                'ItemsTextSuffix' => 'Number {document_number}, due {day+14}.{month}.{year}',
                'ReportLanguage' => 3,
                'TaxingType' => 1,
                'InvoiceMaturity' => 14,
                'DiscountPercentage' => 0.0,
                'VatReverseChargeCodeId' => null,
                'Note' => '',
                'Items' => [['Id' => 1, 'ItemType' => 0, 'Name' => 'Hosting', 'Amount' => 1.0, 'Unit' => 'ks', 'PriceType' => 1, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 1000.0]]],
            ],
            'RecurringSetting' => $setting + [
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
                'SendToSupplier' => true,
                'SendToOtherEmails' => true,
                'SendToAccountant' => false,
            ],
        ]);
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
            if (RecurringStage::KEY === $entry['stage'] && $id === $entry['external_id']) {
                return $entry;
            }
        }

        self::fail(\sprintf('The report has no template %d.', $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function templateRow(string $id): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM recurring_invoice WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * The default item of a template with some fields changed; a null drops one.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function item(array $fields = []): array
    {
        return array_filter($fields + ['ItemType' => 0, 'Name' => 'Hosting', 'Amount' => 1.0, 'Unit' => 'ks', 'PriceType' => 1, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 1000.0]], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @return list<array{mixed, mixed, mixed}> the quantity, price and discount of each line
     */
    private function lineFigures(IdokladImportRun $run, int $id): array
    {
        $entry = $this->entry($run, $id);
        self::assertSame('created', $entry['outcome'], (string) $id);
        $lines = $this->em->getConnection()->fetchAllAssociative('SELECT quantity, price_minor, discount FROM recurring_invoice_line WHERE recurring_invoice_id = :id ORDER BY position', ['id' => $entry['target_id']]);

        return array_map(static fn (array $line): array => [$line['quantity'], $line['price_minor'], $line['discount']], $lines);
    }

    private function templates(): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM recurring_invoice');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function link(int $id): ?IdokladLink
    {
        $this->em->clear();

        return $this->em->getRepository(IdokladLink::class)->findOneBy(['resource' => IdokladResource::RecurringInvoices, 'externalId' => $id]);
    }
}
