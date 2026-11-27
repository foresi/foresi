<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Idoklad\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Contacts\Application\CreateParty;
use Foresi\Idoklad\Application\Import\ContactsStage;
use Foresi\Idoklad\Application\Import\DocumentsStage;
use Foresi\Idoklad\Application\Import\IdokladImportRuns;
use Foresi\Idoklad\Application\Import\PaymentsStage;
use Foresi\Idoklad\Application\Import\RecurringStage;
use Foresi\Idoklad\Application\Import\RunIdokladImport;
use Foresi\Idoklad\Application\Import\SeriesStage;
use Foresi\Idoklad\Domain\IdokladImportRun;
use Foresi\Idoklad\Domain\IdokladLink;
use Foresi\Idoklad\Domain\IdokladPull;
use Foresi\Idoklad\Domain\IdokladRecord;
use Foresi\Idoklad\Domain\IdokladResource;
use Foresi\Idoklad\Domain\ImportMode;
use Foresi\Invoicing\Application\NumberCounter;
use Foresi\Invoicing\Application\SeriesCatalog;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Application\PartyInput;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IdokladImportTest extends KernelTestCase
{
    private const int CZECHIA = 2;
    private const int SLOVAKIA = 1;

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
        $this->record(IdokladResource::Countries, self::CZECHIA, ['Id' => self::CZECHIA, 'Code' => 'CZE', 'Name' => 'Česká republika', 'NameEnglish' => 'Czech Republic']);
        $this->record(IdokladResource::Countries, self::SLOVAKIA, ['Id' => self::SLOVAKIA, 'Code' => 'SVK', 'Name' => 'Slovensko', 'NameEnglish' => 'Slovakia']);
        $pull = new IdokladPull(new \DateTimeImmutable('2026-11-06 09:00:00'));
        $pull->finish(new \DateTimeImmutable('2026-11-06 09:05:00'), 3);
        $this->em->persist($pull);
        $this->em->flush();
    }

    public function testTheStagesRunContactsThenSeriesThenDocumentsThenPaymentsThenRecurringTemplates(): void
    {
        self::assertSame([ContactsStage::KEY, SeriesStage::KEY, DocumentsStage::KEY, PaymentsStage::KEY, RecurringStage::KEY], $this->import()->stages());
        $container = self::getContainer();
        self::assertSame([100, 200, 350], [$container->get(ContactsStage::class)->order(), $container->get(SeriesStage::class)->order(), $container->get(PaymentsStage::class)->order()], 'Room is left between the stages.');
    }

    public function testAFoundContactThatHasEverythingIsNotWritten(): void
    {
        $existing = $this->existing('Example Full a.s.', '34567895', phone: '+420 999 999 999');
        $this->contact(504, ['CompanyName' => 'Example Full a.s.', 'IdentificationNumber' => '34567895', 'Phone' => '+420 111 111 111', 'DefaultInvoiceMaturity' => 30]);

        $run = $this->import()->run(ImportMode::Run);

        self::assertSame(['filled' => ''], $this->entry($run, ContactsStage::KEY, 504)['values']);
        self::assertSame('+420 999 999 999', $this->party($existing)->phone());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM audit_entry WHERE entity_id = \''.$existing.'\' AND action = \'update\''));
    }

    public function testANameDoesNotMatchACompanyWithAnotherRegistrationNumber(): void
    {
        $existing = $this->existing('Example Trading s.r.o.', '34567895');
        $this->contact(506, ['CompanyName' => 'Example Trading s.r.o.', 'IdentificationNumber' => '23456787']);

        $run = $this->import()->run(ImportMode::Run);

        $entry = $this->entry($run, ContactsStage::KEY, 506);
        self::assertSame('created', $entry['outcome']);
        self::assertNotSame($existing, $entry['target_id']);
    }

    public function testTheSameNameTwiceInIdokladIsOneContact(): void
    {
        $this->contact(508, ['CompanyName' => 'Example Duo s.r.o.', 'VatIdentificationNumber' => 'CZ23456787']);
        $this->contact(509, ['CompanyName' => 'Example Duo s.r.o.', 'VatIdentificationNumber' => 'CZ23456787']);

        $run = $this->import()->run(ImportMode::Run);

        self::assertSame('created', $this->entry($run, ContactsStage::KEY, 508)['outcome']);
        $second = $this->entry($run, ContactsStage::KEY, 509);
        self::assertSame('matched', $second['outcome']);
        self::assertSame($this->entry($run, ContactsStage::KEY, 508)['target_id'], $second['target_id']);
    }

    public function testANameMatchesANumberedCompanyWhenTheContactHasNoNumber(): void
    {
        $existing = $this->existing('Example Numbered a.s.', '34567895');
        $this->contact(521, ['CompanyName' => 'Example Numbered a.s.', 'VatIdentificationNumber' => 'CZ23456787']);

        $run = $this->import()->run(ImportMode::Run);

        self::assertSame($existing, $this->entry($run, ContactsStage::KEY, 521)['target_id']);
    }

    public function testANameMatchesOnlyTheCompanyWithoutAnotherNumber(): void
    {
        $this->existing('Example Pair s.r.o.', '34567895');
        $plain = $this->existing('Example Pair s.r.o.', null);
        $this->contact(524, ['CompanyName' => 'Example Pair s.r.o.', 'IdentificationNumber' => '23456787']);

        $run = $this->import()->run(ImportMode::Run);

        $entry = $this->entry($run, ContactsStage::KEY, 524);
        self::assertSame(['matched', $plain], [$entry['outcome'], $entry['target_id']]);
    }

    public function testTwoContactsOfTheSameNumberAreRefusedAsAmbiguous(): void
    {
        foreach (['Example One a.s.', 'Example Two a.s.'] as $name) {
            $this->em->persist(new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '34567895'), null, '', [PartyRole::Customer]));
        }
        $this->em->flush();
        $this->contact(526, ['CompanyName' => 'Example Three a.s.', 'IdentificationNumber' => '34567895']);

        $run = $this->import()->run(ImportMode::Run);

        self::assertSame([ContactsStage::AMBIGUOUS], $this->entry($run, ContactsStage::KEY, 526)['reasons']);
    }

    public function testTheEdgesOfTheCounterAreKeptAndASequenceWithoutTypeIsRefused(): void
    {
        $this->sequence(24, ['DocumentType' => 0, 'Name' => 'First year', 'NumberFormat' => 'ANNN', 'Year' => 1, 'LastNumber' => 1]);
        $this->sequence(25, ['DocumentType' => 0, 'Name' => 'Last year', 'NumberFormat' => 'BNNN', 'Year' => 9999, 'LastNumber' => 1]);
        $this->sequence(26, ['DocumentType' => 0, 'Name' => 'Full', 'NumberFormat' => 'CNNN', 'Year' => 2026, 'LastNumber' => SeriesFormat::MAX_SEQUENCE]);
        $this->sequence(27, ['Name' => 'Untyped', 'NumberFormat' => 'GNNN', 'Year' => 2026, 'LastNumber' => 1]);

        $run = $this->import()->run(ImportMode::Run);

        self::assertSame('created', $this->entry($run, SeriesStage::KEY, 24)['outcome']);
        self::assertSame('created', $this->entry($run, SeriesStage::KEY, 25)['outcome']);
        self::assertSame('created', $this->entry($run, SeriesStage::KEY, 26)['outcome']);
        self::assertSame([SeriesStage::UNKNOWN_TYPE], $this->entry($run, SeriesStage::KEY, 27)['reasons']);
    }

    public function testAnExistingSeriesOfTheSameNumbersIsUsedAndItsCounterNeverGoesBack(): void
    {
        $catalog = self::getContainer()->get(SeriesCatalog::class);
        $existing = Series::standard(DocumentKind::Advance);
        $catalog->store($existing);
        self::getContainer()->get(NumberCounter::class)->advanceTo($existing, new \DateTimeImmutable('2026-03-01'), 100);
        $this->sequence(11, ['DocumentType' => 1, 'Name' => 'Zálohy', 'NumberFormat' => 'Z9RRRRNNNNN', 'Year' => 2026, 'LastNumber' => 7, 'IsDefault' => true]);

        $run = $this->import()->run(ImportMode::Run);

        $entry = $this->entry($run, SeriesStage::KEY, 11);
        self::assertSame('matched', $entry['outcome']);
        self::assertSame($existing->id(), $entry['target_id']);
        self::assertSame('Z920260101', $entry['values']['next_number']);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM series'));
    }

    public function testASeriesThatNoLongerHasTheNumbersOfItsSequenceIsAMismatch(): void
    {
        $catalog = self::getContainer()->get(SeriesCatalog::class);
        $series = new Series('Other', SeriesFormat::fromString('X{NNN}'), DocumentKind::Invoice);
        $catalog->store($series);
        $this->em->persist(new IdokladLink(IdokladResource::NumericSequences, 17, $series->id(), 'b0b0b0b0-0000-4000-8000-000000000000', new \DateTimeImmutable()));
        $this->sequence(17, ['DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 7]);
        $this->sequence(23, ['DocumentType' => 1, 'Name' => 'Zálohy', 'NumberFormat' => 'ZRRRRNNNN', 'Year' => 2026, 'LastNumber' => 7]);

        $run = $this->import()->run(ImportMode::Run);

        self::assertSame('created', $this->entry($run, SeriesStage::KEY, 23)['outcome'], 'The sequences after a mismatch are imported.');

        $entry = $this->entry($run, SeriesStage::KEY, 17);
        self::assertSame('mismatch', $entry['outcome']);
        self::assertSame($series->id(), $entry['target_id']);
        self::assertSame('X{NNN}', $entry['values']['series_format']);
        self::assertSame(0, self::getContainer()->get(NumberCounter::class)->last($series, '2026'));
    }

    public function testAFailedRunStoresNothingButItsError(): void
    {
        $this->contact(518, ['CompanyName' => 'Example Lost s.r.o.', 'VatIdentificationNumber' => 'CZ23456787']);
        $this->em->persist(new IdokladLink(IdokladResource::NumericSequences, 18, 'c0c0c0c0-0000-4000-8000-000000000000', 'b0b0b0b0-0000-4000-8000-000000000000', new \DateTimeImmutable()));
        $this->sequence(18, ['DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 7]);

        $run = $this->import()->run(ImportMode::Run);

        self::assertNotNull($run->error());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM party'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM idoklad_link'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM idoklad_import_run'));
        $this->em->clear();
        self::assertSame($run->error(), self::getContainer()->get(IdokladImportRuns::class)->all()[0]->error());
    }

    public function testWithoutAFinishedPullThereIsNothingToImport(): void
    {
        $this->em->createQuery('DELETE FROM '.IdokladPull::class.' p')->execute();

        $run = $this->import()->run(ImportMode::Check);

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
        $this->em->persist(new IdokladRecord($resource, $id, $payload, new \DateTimeImmutable('2026-11-06 09:01:00')));
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
        $target = self::getContainer()->get(\Foresi\Import\Application\Target\TargetRegistry::class)->get('party');
        $target->update($party->id(), ['bank_account_iban' => 'CZ5508000000001234567899', 'contact_name' => 'Old Person'], \Foresi\Import\Contract\BlankCells::Keep);

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

    private function rows(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
