<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Application;

use App\Idoklad\Application\Import\ContactsStage;
use App\Idoklad\Application\Import\IdokladImportRuns;
use App\Idoklad\Application\Import\RunIdokladImport;
use App\Idoklad\Application\Import\SeriesStage;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladLink;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportMode;
use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\CreateParty;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyInput;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IdokladImportTest extends KernelTestCase
{
    private const int CZECHIA = 2;
    private const int SLOVAKIA = 1;

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
        $container->get(TenantContext::class)->switchTo(TenantId::generate());

        $this->firm = new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', '12345679'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($this->firm);
        $this->record(IdokladResource::Countries, self::CZECHIA, ['Id' => self::CZECHIA, 'Code' => 'CZE', 'Name' => 'Česká republika', 'NameEnglish' => 'Czech Republic']);
        $this->record(IdokladResource::Countries, self::SLOVAKIA, ['Id' => self::SLOVAKIA, 'Code' => 'SVK', 'Name' => 'Slovensko', 'NameEnglish' => 'Slovakia']);
        $pull = new IdokladPull($this->firm, new \DateTimeImmutable('2026-11-06 09:00:00'));
        $pull->finish(new \DateTimeImmutable('2026-11-06 09:05:00'), 3);
        $this->em->persist($pull);
        $this->em->flush();
    }

    public function testTheStagesRunContactsThenSeries(): void
    {
        self::assertSame([ContactsStage::KEY, SeriesStage::KEY], $this->import()->stages());
    }

    public function testACompanyIsCreatedWithEverythingItBrings(): void
    {
        $this->contact(501, [
            'CompanyName' => 'Example Trading s.r.o.',
            'IdentificationNumber' => '23456787',
            'VatIdentificationNumber' => 'CZ23456787',
            'Email' => 'billing@example.com',
            'Phone' => '+420 123 456 789',
            'Www' => 'https://example.com',
            'Title' => 'Ing.',
            'Firstname' => 'Jana',
            'Surname' => 'Example',
            'Iban' => 'CZ65 0800 0000 1920 0014 5399',
            'DefaultInvoiceMaturity' => 21,
            'SendReminders' => false,
            'DeliveryAddresses' => [
                ['Id' => 1, 'IsDefault' => false, 'Street' => 'Other Street 2', 'City' => 'Other City', 'PostalCode' => '200 00', 'CountryId' => self::CZECHIA],
                ['Id' => 2, 'IsDefault' => true, 'Street' => 'Store Street 3', 'City' => 'Bratislava', 'PostalCode' => '811 01', 'CountryId' => self::SLOVAKIA],
            ],
        ]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertNull($run->error());
        $entry = $this->entry($run, ContactsStage::KEY, 501);
        self::assertSame('created', $entry['outcome']);
        $party = $this->party($entry['target_id']);
        self::assertSame('Example Trading s.r.o.', $party->displayName());
        self::assertSame(PartyKind::Company, $party->kind());
        self::assertSame('23456787', $party->registrationNumber()?->value);
        self::assertSame('CZ23456787', $party->vatId()?->toString());
        self::assertSame('+420 123 456 789', $party->phone());
        self::assertSame('https://example.com', $party->website());
        self::assertSame([PartyRole::Customer], $party->roles());
        self::assertSame(21, $party->invoicingDefaults()->dueDays);
        $details = self::getContainer()->get(PartyDetails::class);
        self::assertSame('billing@example.com', $details->generalEmail($party)?->address());
        self::assertSame('Example Street 9', $details->billingAddress($party)?->address()->street);
        $delivery = array_values(array_filter($details->addresses($party), static fn ($address): bool => AddressType::Delivery === $address->type()));
        self::assertSame('Store Street 3', $delivery[0]->address()->street, 'The default delivery address, not the first one.');
        self::assertSame('SK', $delivery[0]->address()->countryCode);
        self::assertSame('CZ6508000000192000145399', $details->bankAccounts($party)[0]->iban()->toString());
        $links = $details->contactLinks($party);
        self::assertCount(1, $links);
        self::assertSame('Ing. Jana Example', $links[0]->person()->displayName());

        $settings = self::getContainer()->get(ContactSettingsForFirm::class);
        self::assertTrue($settings->hasOwnDefaults($party, $this->storedFirm()), 'The reminder setting is the firm\'s own.');
        self::assertFalse($settings->defaultsFor($party, $this->storedFirm())->sendReminders);
        self::assertTrue($party->invoicingDefaults()->sendReminders, 'The shared setting stays.');

        $link = $this->links()[0];
        self::assertSame(IdokladResource::Contacts, $link->resource());
        self::assertSame(501, $link->externalId());
        self::assertSame($party->id(), $link->targetId());
        self::assertSame($run->id(), $link->runId());
        self::assertSame($this->firm->id(), $link->firm()->id());
        self::assertEquals($run->startedAt(), $link->createdAt());
    }

    public function testAPersonIsCreatedFromTheNameAndKeepsTheSharedReminders(): void
    {
        $this->contact(502, ['Firstname' => 'Petr', 'Surname' => 'Example', 'SendReminders' => true]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $party = $this->party($this->entry($run, ContactsStage::KEY, 502)['target_id']);
        self::assertSame('Petr Example', $party->displayName());
        self::assertSame(PartyKind::Person, $party->kind());
        self::assertFalse(self::getContainer()->get(ContactSettingsForFirm::class)->hasOwnDefaults($party, $this->storedFirm()));
    }

    public function testAContactIsMatchedByRegistrationNumberAndGetsOnlyWhatItLacks(): void
    {
        $existing = $this->existing('Example Old Name a.s.', '34567895', phone: '+420 999 999 999');
        $this->contact(503, [
            'CompanyName' => 'Example New Name a.s.',
            'IdentificationNumber' => '34567895',
            'Email' => 'new@example.com',
            'Phone' => '+420 111 111 111',
            'Www' => 'https://example.com',
            'Street' => 'New Street 1',
            'Iban' => 'CZ65 0800 0000 1920 0014 5399',
            'Firstname' => 'Jana',
            'Surname' => 'Example',
        ]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, ContactsStage::KEY, 503);
        self::assertSame('matched', $entry['outcome']);
        self::assertSame($existing, $entry['target_id']);
        self::assertSame(['filled' => 'website'], $entry['values'], 'The bank account and the contact person it has are enough.');
        $party = $this->party($existing);
        self::assertSame('Example Old Name a.s.', $party->displayName());
        self::assertSame('+420 999 999 999', $party->phone());
        self::assertSame('https://example.com', $party->website());
        $details = self::getContainer()->get(PartyDetails::class);
        self::assertSame('old@example.com', $details->generalEmail($party)?->address());
        self::assertSame('Old Street 1', $details->billingAddress($party)?->address()->street);
        self::assertCount(1, $details->bankAccounts($party));
        self::assertCount(1, $details->contactLinks($party));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM party WHERE kind = \'company\''));
    }

    public function testAFoundContactThatHasEverythingIsNotWritten(): void
    {
        $existing = $this->existing('Example Full a.s.', '34567895', phone: '+420 999 999 999');
        $this->contact(504, ['CompanyName' => 'Example Full a.s.', 'IdentificationNumber' => '34567895', 'Phone' => '+420 111 111 111']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame(['filled' => ''], $this->entry($run, ContactsStage::KEY, 504)['values']);
        self::assertSame('+420 999 999 999', $this->party($existing)->phone());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM audit_entry WHERE entity_id = \''.$existing.'\' AND action = \'update\''));
    }

    public function testAContactIsMatchedByItsNormalisedNameAndGetsItsRegistrationNumber(): void
    {
        $existing = $this->existing('Example  Trading s.r.o.', null);
        $this->contact(505, ['CompanyName' => ' example trading S.R.O. ', 'IdentificationNumber' => '23456787', 'VatIdentificationNumber' => 'CZ23456787', 'Iban' => 'CZ65 0800 0000 1920 0014 5399', 'Firstname' => 'Jana', 'Surname' => 'Example']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, ContactsStage::KEY, 505);
        self::assertSame('matched', $entry['outcome']);
        self::assertSame($existing, $entry['target_id']);
        $party = $this->party($existing);
        self::assertSame('23456787', $party->registrationNumber()?->value);
        self::assertSame('CZ', $party->registrationNumber()->countryCode);
        self::assertSame('CZ45678901', $party->vatId()?->toString());
        self::assertSame('Example  Trading s.r.o.', $party->displayName());
        self::assertSame(['filled' => 'registration_number,contact_first_name,contact_last_name,bank_account_iban,registration_country'], $entry['values']);
        $details = self::getContainer()->get(PartyDetails::class);
        self::assertSame('CZ6508000000192000145399', $details->bankAccounts($party)[0]->iban()->toString());
        self::assertSame('Jana Example', $details->contactLinks($party)[0]->person()->displayName());
    }

    public function testANameDoesNotMatchACompanyWithAnotherRegistrationNumber(): void
    {
        $existing = $this->existing('Example Trading s.r.o.', '34567895');
        $this->contact(506, ['CompanyName' => 'Example Trading s.r.o.', 'IdentificationNumber' => '23456787']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, ContactsStage::KEY, 506);
        self::assertSame('created', $entry['outcome']);
        self::assertNotSame($existing, $entry['target_id']);
    }

    public function testTwoContactsOfTheSameNameAreRefusedAsAmbiguous(): void
    {
        $this->existing('Example Twin s.r.o.', null);
        $this->existing('Example Twin s.r.o.', null);
        $this->contact(507, ['CompanyName' => 'Example Twin s.r.o.', 'VatIdentificationNumber' => 'CZ23456787']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, ContactsStage::KEY, 507);
        self::assertSame('refused', $entry['outcome']);
        self::assertSame([ContactsStage::AMBIGUOUS], $entry['reasons']);
        self::assertSame(['display_name' => 'Example Twin s.r.o.'], $entry['values']);
        self::assertSame([], $this->links());
    }

    public function testTheSameNameTwiceInIdokladIsOneContact(): void
    {
        $this->contact(508, ['CompanyName' => 'Example Duo s.r.o.', 'VatIdentificationNumber' => 'CZ23456787']);
        $this->contact(509, ['CompanyName' => 'Example Duo s.r.o.', 'VatIdentificationNumber' => 'CZ23456787']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertSame('created', $this->entry($run, ContactsStage::KEY, 508)['outcome']);
        $second = $this->entry($run, ContactsStage::KEY, 509);
        self::assertSame('matched', $second['outcome']);
        self::assertSame($this->entry($run, ContactsStage::KEY, 508)['target_id'], $second['target_id']);
    }

    public function testAContactWithoutAKnownCountryOrWithBadValuesIsRefused(): void
    {
        $this->contact(510, ['CompanyName' => 'Example Abroad Ltd', 'VatIdentificationNumber' => 'CZ23456787', 'CountryId' => 99]);
        $this->contact(511, ['CompanyName' => 'Example Store s.r.o.', 'VatIdentificationNumber' => 'CZ23456787', 'DeliveryAddresses' => [['Street' => 'Store Street 3', 'City' => 'Example City', 'PostalCode' => '100 00']]]);
        $this->contact(512, ['CompanyName' => 'Example Mail s.r.o.', 'VatIdentificationNumber' => 'CZ23456787', 'Email' => 'not an e-mail']);
        $this->contact(513, ['CompanyName' => 'Example Number s.r.o.', 'IdentificationNumber' => '12345678']);
        $this->contact(514, ['CompanyName' => '', 'Street' => '']);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $refused = fn (int $id): array => [$this->entry($run, ContactsStage::KEY, $id)['outcome'], $this->entry($run, ContactsStage::KEY, $id)['reasons'], $this->entry($run, ContactsStage::KEY, $id)['values']];
        self::assertSame(['refused', [ContactsStage::UNKNOWN_COUNTRY], ['billing_country' => '99']], $refused(510));
        self::assertSame(['refused', [ContactsStage::UNKNOWN_COUNTRY], ['delivery_country' => '']], $refused(511));
        self::assertSame(['refused', ['import.error.invalid_email'], ['email' => 'not']], $refused(512));
        self::assertSame(['refused', ['import.error.invalid_registration_number'], ['registration_number' => '12345678']], $refused(513));
        self::assertSame(['refused', ['import.error.required'], ['display_name' => '', 'billing_street' => '']], $refused(514));
        self::assertSame(['contacts' => ['refused' => 5]], $run->counts());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM party'));
        self::assertSame([], $this->links());
    }

    public function testASeriesIsCreatedForTheYearsOfASequenceAndContinuesItsNumbers(): void
    {
        $this->sequence(1, ['DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2025, 'LastNumber' => 41, 'IsDefault' => true]);
        $this->sequence(2, ['DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 7, 'IsDefault' => true]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $first = $this->entry($run, SeriesStage::KEY, 1);
        $second = $this->entry($run, SeriesStage::KEY, 2);
        self::assertSame('created', $first['outcome']);
        self::assertSame('20250042', $first['values']['next_number']);
        self::assertSame('matched', $second['outcome']);
        self::assertSame($first['target_id'], $second['target_id']);
        self::assertSame('20260008', $second['values']['next_number']);
        $series = $this->series($first['target_id']);
        self::assertSame('Faktury', $series->name());
        self::assertSame(DocumentKind::Invoice, $series->kind());
        self::assertSame('{YYYY}{NNNN}', $series->format()->toString());
        self::assertTrue($series->isDefault());
        self::assertSame(['series' => ['created' => 1, 'matched' => 1]], $run->counts());
    }

    public function testEachDocumentTypeIsItsKindAndOthersAreRefused(): void
    {
        $this->sequence(3, ['DocumentType' => 1, 'Name' => 'Zálohy', 'NumberFormat' => 'ZRRNNN', 'Year' => 2026, 'LastNumber' => 0, 'IsDefault' => false]);
        $this->sequence(4, ['DocumentType' => 3, 'Name' => 'Dobropisy', 'NumberFormat' => 'DRRRRMMNNN', 'Year' => 2026, 'LastNumber' => 2]);
        $this->sequence(5, ['DocumentType' => 10, 'Name' => 'Daňové doklady', 'NumberFormat' => 'DD-RRRR-NNNN', 'Year' => 2026, 'LastNumber' => 5]);
        $this->sequence(6, ['DocumentType' => 5, 'Name' => 'Přijaté', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 5]);
        $this->sequence(7, ['DocumentType' => 0, 'Name' => 'Bad', 'NumberFormat' => 'RRRNNN', 'Year' => 2026, 'LastNumber' => 5]);
        $this->sequence(8, ['DocumentType' => 0, 'Name' => 'Spaces', 'NumberFormat' => 'F RRNNN', 'Year' => 2026, 'LastNumber' => 5]);
        $this->sequence(9, ['DocumentType' => 0, 'Name' => 'No year', 'NumberFormat' => 'XNNN', 'LastNumber' => 5]);
        $this->sequence(10, ['DocumentType' => 0, 'Name' => 'Negative', 'NumberFormat' => 'YNNN', 'Year' => 2026, 'LastNumber' => -1]);
        $this->sequence(20, ['DocumentType' => 0, 'Name' => 'Far', 'NumberFormat' => 'WNNN', 'Year' => 10000, 'LastNumber' => 1]);
        $this->sequence(21, ['DocumentType' => 0, 'Name' => 'Huge', 'NumberFormat' => 'VNNN', 'Year' => 2026, 'LastNumber' => SeriesFormat::MAX_SEQUENCE + 1]);
        $this->sequence(22, ['DocumentType' => 0, 'Name' => 'Ancient', 'NumberFormat' => 'UNNN', 'Year' => 0, 'LastNumber' => 1]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $kind = fn (int $id): array => [$this->series($this->entry($run, SeriesStage::KEY, $id)['target_id'])->kind(), $this->series($this->entry($run, SeriesStage::KEY, $id)['target_id'])->format()->toString()];
        self::assertSame([DocumentKind::Advance, 'Z{YY}{NNN}'], $kind(3));
        self::assertSame([DocumentKind::CorrectionDocument, 'D{YYYY}{MM}{NNN}'], $kind(4));
        self::assertSame([DocumentKind::PaymentTaxDocument, 'DD-{YYYY}-{NNNN}'], $kind(5));
        self::assertSame('Z26001', $this->entry($run, SeriesStage::KEY, 3)['values']['next_number']);
        self::assertFalse($this->series($this->entry($run, SeriesStage::KEY, 3)['target_id'])->isDefault());
        self::assertSame([SeriesStage::UNKNOWN_TYPE], $this->entry($run, SeriesStage::KEY, 6)['reasons']);
        self::assertSame(['document_type' => 5, 'number_format' => 'RRRRNNNN', 'year' => 2026, 'last_number' => 5], $this->entry($run, SeriesStage::KEY, 6)['values']);
        self::assertSame([SeriesStage::INVALID_FORMAT], $this->entry($run, SeriesStage::KEY, 7)['reasons']);
        self::assertSame([SeriesStage::INVALID_FORMAT], $this->entry($run, SeriesStage::KEY, 8)['reasons']);
        self::assertSame([SeriesStage::INVALID_COUNTER], $this->entry($run, SeriesStage::KEY, 9)['reasons']);
        self::assertSame([SeriesStage::INVALID_COUNTER], $this->entry($run, SeriesStage::KEY, 10)['reasons']);
        self::assertSame([SeriesStage::INVALID_COUNTER], $this->entry($run, SeriesStage::KEY, 20)['reasons']);
        self::assertSame([SeriesStage::INVALID_COUNTER], $this->entry($run, SeriesStage::KEY, 21)['reasons']);
        self::assertSame([SeriesStage::INVALID_COUNTER], $this->entry($run, SeriesStage::KEY, 22)['reasons']);
        self::assertSame(3, $this->rows('SELECT COUNT(*) FROM series'));
    }

    public function testAnExistingSeriesOfTheSameNumbersIsUsedAndItsCounterNeverGoesBack(): void
    {
        $catalog = self::getContainer()->get(SeriesCatalog::class);
        $existing = Series::standard($this->firm, DocumentKind::Advance);
        $catalog->store($existing);
        self::getContainer()->get(NumberCounter::class)->advanceTo($existing, new \DateTimeImmutable('2026-03-01'), 100);
        $this->sequence(11, ['DocumentType' => 1, 'Name' => 'Zálohy', 'NumberFormat' => 'Z9RRRRNNNNN', 'Year' => 2026, 'LastNumber' => 7, 'IsDefault' => true]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, SeriesStage::KEY, 11);
        self::assertSame('matched', $entry['outcome']);
        self::assertSame($existing->id(), $entry['target_id']);
        self::assertSame('Z920260101', $entry['values']['next_number']);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM series'));
    }

    public function testANewSeriesNeverTakesTheDefaultNorTheNameOfAnother(): void
    {
        $catalog = self::getContainer()->get(SeriesCatalog::class);
        $standard = Series::standard($this->firm, DocumentKind::Invoice);
        $catalog->store($standard);
        $this->sequence(12, ['DocumentType' => 0, 'Name' => 'Export', 'NumberFormat' => 'EXRRNNN', 'Year' => 2026, 'LastNumber' => 1, 'IsDefault' => true]);
        $this->sequence(13, ['DocumentType' => 0, 'Name' => 'STANDARD', 'NumberFormat' => 'SRRNNN', 'Year' => 2026, 'LastNumber' => 1]);
        $this->sequence(14, ['DocumentType' => 3, 'Name' => ' ', 'NumberFormat' => 'ORRNNN', 'Year' => 2026, 'LastNumber' => 1]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $created = $this->series($this->entry($run, SeriesStage::KEY, 12)['target_id']);
        self::assertFalse($created->isDefault());
        self::assertTrue($this->series($standard->id())->isDefault());
        $taken = $this->entry($run, SeriesStage::KEY, 13);
        self::assertSame([SeriesStage::NAME_TAKEN], $taken['reasons']);
        self::assertSame('STANDARD', $taken['values']['name']);
        self::assertSame(DocumentKind::CorrectionDocument->standardSeriesName(), $this->series($this->entry($run, SeriesStage::KEY, 14)['target_id'])->name());
    }

    public function testACheckWritesNothingButItsReport(): void
    {
        $this->contact(515, ['CompanyName' => 'Example Check s.r.o.', 'VatIdentificationNumber' => 'CZ23456787']);
        $this->sequence(15, ['DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 7, 'IsDefault' => true]);
        $tables = ['party', 'party_address', 'party_email', 'series', 'series_counter', 'idoklad_link', 'audit_entry'];
        $before = array_map(fn (string $table): int => $this->rows('SELECT COUNT(*) FROM '.$table), $tables);

        $run = $this->import()->run($this->firm, ImportMode::Check);

        self::assertNull($run->error());
        self::assertSame(ImportMode::Check, $run->mode());
        self::assertSame(['contacts' => ['created' => 1], 'series' => ['created' => 1]], $run->counts());
        self::assertSame('20260008', $this->entry($run, SeriesStage::KEY, 15)['values']['next_number']);
        self::assertSame($before, array_map(fn (string $table): int => $this->rows('SELECT COUNT(*) FROM '.$table), $tables));
        $this->em->clear();
        $stored = self::getContainer()->get(IdokladImportRuns::class)->forFirm($this->storedFirm());
        self::assertCount(1, $stored);
        self::assertSame($run->counts(), $stored[0]->counts());
        self::assertSame($run->report(), $stored[0]->report());
    }

    public function testARunIsStoredAndASecondRunFindsTheLinks(): void
    {
        $this->contact(516, ['CompanyName' => 'Example Again s.r.o.', 'VatIdentificationNumber' => 'CZ23456787']);
        $this->sequence(16, ['DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 7]);
        $first = $this->import()->run($this->firm, ImportMode::Run);
        $this->em->clear();
        $this->em->getRepository(IdokladRecord::class)->findOneBy(['resource' => IdokladResource::NumericSequences, 'externalId' => 16])?->replace(['Id' => 16, 'DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 9], new \DateTimeImmutable('2026-11-06 11:00:00'));
        $this->em->flush();

        $second = $this->import()->run($this->storedFirm(), ImportMode::Run);

        self::assertSame(['contacts' => ['already_linked' => 1], 'series' => ['already_linked' => 1]], $second->counts());
        self::assertSame($this->entry($first, ContactsStage::KEY, 516)['target_id'], $this->entry($second, ContactsStage::KEY, 516)['target_id']);
        self::assertSame('20260010', $this->entry($second, SeriesStage::KEY, 16)['values']['next_number'], 'A linked series follows iDoklad on.');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM party'));
        self::assertCount(2, $this->links());
        $this->em->clear();
        $runs = self::getContainer()->get(IdokladImportRuns::class)->forFirm($this->storedFirm());
        self::assertSame([$second->id(), $first->id()], array_map(static fn (IdokladImportRun $run): string => $run->id(), $runs));
        $stored = $runs[1];
        self::assertSame(ImportMode::Run, $stored->mode());
        self::assertSame($this->em->getRepository(IdokladPull::class)->findAll()[0]->id(), $stored->pullId());
        self::assertSame($first->startedAt()->format('Y-m-d H:i:s'), $stored->startedAt()->format('Y-m-d H:i:s'));
        self::assertNotNull($stored->finishedAt());
        self::assertSame($this->firm->id(), $stored->firm()->id());
        self::assertSame(['stage' => 'contacts', 'external_id' => 516, 'outcome' => 'created', 'target_id' => $this->entry($first, ContactsStage::KEY, 516)['target_id'], 'reasons' => [], 'values' => []], $stored->report()[0]);
    }

    public function testASeriesThatNoLongerHasTheNumbersOfItsSequenceIsAMismatch(): void
    {
        $catalog = self::getContainer()->get(SeriesCatalog::class);
        $series = new Series($this->firm, 'Other', SeriesFormat::fromString('X{NNN}'), DocumentKind::Invoice);
        $catalog->store($series);
        $this->em->persist(new IdokladLink($this->firm, IdokladResource::NumericSequences, 17, $series->id(), 'b0b0b0b0-0000-4000-8000-000000000000', new \DateTimeImmutable()));
        $this->sequence(17, ['DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 7]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        $entry = $this->entry($run, SeriesStage::KEY, 17);
        self::assertSame('mismatch', $entry['outcome']);
        self::assertSame($series->id(), $entry['target_id']);
        self::assertSame('X{NNN}', $entry['values']['series_format']);
        self::assertSame(0, self::getContainer()->get(NumberCounter::class)->last($series, '2026'));
    }

    public function testAFailedRunStoresNothingButItsError(): void
    {
        $this->contact(518, ['CompanyName' => 'Example Lost s.r.o.', 'VatIdentificationNumber' => 'CZ23456787']);
        $this->em->persist(new IdokladLink($this->firm, IdokladResource::NumericSequences, 18, 'c0c0c0c0-0000-4000-8000-000000000000', 'b0b0b0b0-0000-4000-8000-000000000000', new \DateTimeImmutable()));
        $this->sequence(18, ['DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 7]);

        $run = $this->import()->run($this->firm, ImportMode::Run);

        self::assertNotNull($run->error());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM party'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM idoklad_link'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM idoklad_import_run'));
        $this->em->clear();
        self::assertSame($run->error(), self::getContainer()->get(IdokladImportRuns::class)->forFirm($this->storedFirm())[0]->error());
    }

    public function testAFirmWithoutAFinishedPullHasNothingToImport(): void
    {
        $other = new LegalEntity('Example Other s.r.o.', new RegistrationNumber('CZ', '45678901'), null, new Address('Example Street 2', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($other);
        $this->em->flush();

        $run = $this->import()->run($other, ImportMode::Check);

        self::assertSame(RunIdokladImport::NO_SNAPSHOT, $run->error());
        self::assertNull($run->pullId());
        self::assertSame([], $run->counts());
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM idoklad_import_run'));
    }

    private function import(): RunIdokladImport
    {
        return self::getContainer()->get('test.idoklad_import');
    }

    /**
     * A Czech company in Example City unless the fields say otherwise.
     *
     * @param array<string, mixed> $fields
     */
    private function contact(int $id, array $fields): void
    {
        $this->record(IdokladResource::Contacts, $id, $fields + ['Id' => $id, 'Street' => 'Example Street 9', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => self::CZECHIA]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function sequence(int $id, array $fields): void
    {
        $this->record(IdokladResource::NumericSequences, $id, $fields + ['Id' => $id]);
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
     * A company with an e-mail and a billing address; with a registration
     * number also a bank account and a contact person, else a VAT id.
     */
    private function existing(string $name, ?string $registrationNumber, string $phone = ''): string
    {
        $create = self::getContainer()->get(CreateParty::class);
        $party = $create->createFrom(new PartyInput(PartyKind::Company, $name, null === $registrationNumber ? null : new RegistrationNumber('CZ', $registrationNumber), null === $registrationNumber ? VatId::fromString('CZ45678901') : null, 'old@example.com', $phone, [PartyRole::Customer], new Address('Old Street 1', 'Old City', '300 00', 'CZ')));
        if (null === $registrationNumber) {
            return $party->id();
        }
        $target = self::getContainer()->get(\App\Import\Application\Target\TargetRegistry::class)->get('party');
        $target->update($party->id(), ['bank_account_iban' => 'CZ5508000000001234567899', 'contact_name' => 'Old Person'], \App\Import\Domain\BlankCells::Keep);

        return $party->id();
    }

    /**
     * @return array{stage: string, external_id: int, outcome: string, target_id: string|null, reasons: list<string>, values: array<string, int|string|bool|null>}
     */
    private function entry(IdokladImportRun $run, string $stage, int $id): array
    {
        foreach ($run->report() as $entry) {
            if ($stage === $entry['stage'] && $id === $entry['external_id']) {
                return $entry;
            }
        }

        self::fail(\sprintf('The report has no entry for %s %d.', $stage, $id));
    }

    private function party(?string $id): Party
    {
        return $this->em->find(Party::class, (string) $id) ?? self::fail('The contact is stored.');
    }

    private function series(?string $id): Series
    {
        return $this->em->find(Series::class, (string) $id) ?? self::fail('The series is stored.');
    }

    /**
     * @return list<IdokladLink>
     */
    private function links(): array
    {
        return $this->em->getRepository(IdokladLink::class)->findAll();
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
