<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Import;

use App\Import\Application\CheckImport;
use App\Import\Application\ConfigureImport;
use App\Import\Application\Imports;
use App\Import\Application\RunImport;
use App\Import\Application\StartImport;
use App\Import\Application\UndoImport;
use App\Import\Domain\BlankCells;
use App\Import\Domain\ColumnProposal;
use App\Import\Domain\DateOrder;
use App\Import\Domain\DecimalSeparator;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\ImportRow;
use App\Import\Domain\ImportState;
use App\Import\Domain\MatchReason;
use App\Import\Domain\RowOutcome;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyQuery;
use App\Party\Application\RecheckRegistry;
use App\Party\Domain\ContactLink;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Party;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Infrastructure\Import\IdokladContactPreset;
use App\Party\Infrastructure\Import\PartyImportTarget;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Import\Support\RunMessages;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Contacts through the real import pipeline: upload, read, check, run and undo,
 * with the messages handled by the in-memory transport as a worker would.
 */
final class PartyImportEndToEndTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string FIXTURE = __DIR__.'/../../Fixtures/idoklad-contacts.csv';

    private EntityManagerInterface $em;
    private RunMessages $worker;
    private InMemoryTransport $registry;
    private string $dir;

    protected function setUp(): void
    {
        self::mockTime('2026-10-01 10:00:00');
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $registry = $container->get('messenger.transport.registry');
        self::assertInstanceOf(InMemoryTransport::class, $registry);
        $this->registry = $registry;
        $this->worker = new RunMessages($transport, $container->get(MessageBusInterface::class));

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->dir = sys_get_temp_dir().'/foresi-party-import-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->service(TenantContext::class)->switchTo(TenantId::generate());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
        parent::tearDown();
    }

    public function testTheIdokladExportIsRecognisedCheckedAndImported(): void
    {
        $id = $this->upload((string) file_get_contents(self::FIXTURE));

        $import = $this->fresh($id);
        self::assertSame(ImportState::Mapping, $import->state());
        self::assertSame(IdokladContactPreset::KEY, $import->presetKey());
        $proposals = array_map(static fn (ColumnProposal $proposal): array => [$import->headers()[$proposal->column], $proposal->fieldKey, $proposal->reason], $import->proposals());
        self::assertContains(['Sleva', null, MatchReason::Preset], $proposals);
        self::assertContains(['Nastavení odesílaní upomínek', null, MatchReason::Preset], $proposals);
        self::assertContains(['www', 'website', MatchReason::Preset], $proposals);
        self::assertSame('registration_number', $import->options()->matchKey);

        $this->configure($id, ImportMode::Update);
        $this->service(CheckImport::class)->check($id);
        $this->worker->run();
        $checked = $this->fresh($id);
        self::assertSame(ImportState::Checked, $checked->state());
        self::assertSame([3, 1], [$checked->created(), $checked->failed()]);

        $this->service(RunImport::class)->run($id);
        $this->worker->run();

        $import = $this->fresh($id);
        self::assertSame(ImportState::FinishedWithErrors, $import->state());
        self::assertSame([3, 0, 1], [$import->created(), $import->updated(), $import->failed()]);
        $errors = $this->errorsOf($import);
        self::assertSame(['registration_number' => 'import.error.invalid_registration_number', 'vat_id' => 'import.error.invalid_vat_id'], $errors[5]);

        $company = $this->partyNamed('Example Company s.r.o.');
        self::assertSame(PartyKind::Company, $company->kind());
        self::assertSame('12345687', $company->registrationNumber()?->value);
        self::assertSame('CZ12345687', $company->vatId()?->toString());
        self::assertSame('+420 123 456 789', $company->phone());
        self::assertSame('+420 987 654 321', $company->mobile());
        self::assertSame('https://www.example.com', $company->website());
        self::assertSame(14, $company->invoicingDefaults()->dueDays);
        $billing = $this->details()->billingAddress($company)?->address();
        self::assertSame(['Example Street 1', 'Praha', '110 00', 'CZ'], [$billing?->street, $billing?->city, $billing?->postalCode, $billing?->countryCode]);
        self::assertSame([['testing@example.com', EmailPurpose::General], ['billing@example.com', EmailPurpose::Copy]], array_map(static fn (PartyEmail $email): array => [$email->address(), $email->purpose()], $this->details()->emails($company)));
        $links = $this->details()->contactLinks($company);
        self::assertCount(1, $links);
        self::assertSame('Ing. Jana Example', $links[0]->person()->displayName());
        self::assertTrue($links[0]->isPrimary());

        $person = $this->partyNamed('Jan Example');
        self::assertSame(PartyKind::Person, $person->kind());
        self::assertSame([], $this->details()->contactLinks($person));
        self::assertSame('Ostrava', $this->details()->billingAddress($person)?->address()->city);

        $trade = $this->partyNamed('Example Trade a.s.');
        self::assertSame('Ústí nad Labem', $this->details()->billingAddress($trade)?->address()->city);
        self::assertSame(['CZ1801000000000123456789'], array_map(static fn (\App\Party\Domain\PartyBankAccount $account): string => $account->iban()->toString(), $this->details()->bankAccounts($trade)));
        self::assertTrue($this->details()->bankAccounts($trade)[0]->isPrimary());

        // Only the company has a VAT id to check; the registers are asked later, never during the import.
        $rechecks = array_map(static fn (\Symfony\Component\Messenger\Envelope $envelope): object => $envelope->getMessage(), [...$this->registry->getSent()]);
        self::assertEquals([new RecheckRegistry($company->id(), 'import:'.$id)], $rechecks);
    }

    public function testARowWhoseWriteFailsInTheDatabaseIsAnErrorAndTheOtherRowsAreWritten(): void
    {
        // Stands in for a constraint the importer cannot foresee: one e-mail address per tenant.
        $this->em->getConnection()->executeStatement('CREATE UNIQUE INDEX test_uniq_party_email ON party_email (tenant_id, address)');
        $csv = "Firma,IČ,E-mailová adresa,Ulice,Město,PSČ\n"
            ."Example One,12345687,same@example.com,Example Street 1,Praha,110 00\n"
            ."Example Two,12345695,same@example.com,Example Street 2,Praha,110 00\n"
            ."Example Three,,three@example.com,Example Street 3,Praha,110 00\n";

        $id = $this->checkedAndRun($csv);

        $import = $this->fresh($id);
        self::assertSame(ImportState::FinishedWithErrors, $import->state());
        self::assertSame([2, 1], [$import->created(), $import->failed()]);
        self::assertSame([3 => ['' => 'import.error.write_failed']], array_filter($this->errorsOf($import)));
        self::assertSame(['Example One', 'Example Three'], $this->names());
        // Nothing of the failed row is left behind: no party, no address, no audit entry under the import.
        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM party_address'));
        self::assertSame(2, $this->rowCount("SELECT COUNT(*) FROM audit_entry WHERE entity_class = ? AND action = 'created'", [Party::class]));
        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM party_email'));
    }

    public function testUndoLeavesAPartyWhoseEmailWasEditedAfterTheImportAndRevertsTheOthers(): void
    {
        $csv = "Firma,IČ,DIČ,E-mailová adresa,Ulice,Město,PSČ\n"
            ."Example One,12345687,,one@example.com,Example Street 1,Praha,110 00\n"
            ."Example Two,12345695,,two@example.com,Example Street 2,Praha,110 00\n";
        $first = $this->checkedAndRun($csv);
        self::assertSame(ImportState::Finished, $this->fresh($first)->state());

        self::mockTime('2026-10-01 11:00:00');
        $update = "Firma,IČ,DIČ,E-mailová adresa,Ulice,Město,PSČ\n"
            ."Example One Renamed,12345687,CZ12345687,one@example.com,Example Street 9,Brno,602 00\n"
            ."Example Two Renamed,12345695,,two@example.com,Example Street 8,Brno,602 00\n";
        $second = $this->checkedAndRun($update);
        self::assertSame([0, 2], [$this->fresh($second)->created(), $this->fresh($second)->updated()]);

        // Someone changes only the e-mail of the second party after the import.
        self::mockTime('2026-10-01 12:00:00');
        $two = $this->partyNamed('Example Two Renamed');
        $this->details()->generalEmail($two)?->change('edited@example.com', EmailPurpose::General);
        $this->em->flush();

        self::mockTime('2026-10-02 10:00:00');
        $this->service(UndoImport::class)->undo($second);
        $this->worker->run();

        $import = $this->fresh($second);
        self::assertSame(ImportState::Undone, $import->state());
        self::assertSame([1, 1], [$import->reverted(), $import->changedAfterImport()]);
        self::assertSame([RowOutcome::Reverted, RowOutcome::ChangedAfterImport], array_map(static fn (ImportRow $row): ?RowOutcome => $row->outcome(), $this->service(Imports::class)->rowsOfChunk($import, 0)));
        $one = $this->partyNamed('Example One');
        self::assertNull($one->vatId());
        self::assertSame('Praha', $this->details()->billingAddress($one)?->address()->city);
        self::assertSame('Brno', $this->details()->billingAddress($this->partyNamed('Example Two Renamed'))?->address()->city);
    }

    public function testUndoArchivesCreatedPartiesAndTheContactPersonsTheyBrought(): void
    {
        $id = $this->checkedAndRun((string) file_get_contents(self::FIXTURE));

        self::mockTime('2026-10-02 10:00:00');
        $this->service(UndoImport::class)->undo($id);
        $this->worker->run();

        self::assertSame(ImportState::Undone, $this->fresh($id)->state());
        self::assertSame(3, $this->fresh($id)->reverted());
        self::assertSame([], $this->names());
        self::assertSame(4, $this->rowCount('SELECT COUNT(*) FROM party WHERE archived_at IS NOT NULL'));
        // Archived, never deleted: documents may refer to them.
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM contact_link'));
    }

    /**
     * @return iterable<string, array{string, string, string}> header, key of the first row, key of the second row
     */
    public static function keysNamingOneParty(): iterable
    {
        yield 'registration number with and without its leading zero' => ['IČ', '0000019', '00000019'];
        yield 'one e-mail and a list that starts with it' => ['E-mailová adresa', 'one@example.com', '"ONE@example.com, cc@example.com"'];
    }

    #[DataProvider('keysNamingOneParty')]
    public function testTwoRowsNamingOnePartyUpdateItOnceAndTheUndoPutsItBack(string $header, string $first, string $second): void
    {
        $this->checkedAndRun("Firma,IČ,E-mailová adresa,Ulice,Město,PSČ\nExample One,00000019,one@example.com,Example Street 1,Praha,110 00\n");

        self::mockTime('2026-10-01 11:00:00');
        $id = $this->checkedAndRun("Firma,{$header},Ulice,Město,PSČ\nRenamed A,{$first},Example Street 2,Brno,602 00\nRenamed B,{$second},Example Street 3,Ostrava,702 00\n", 'second.csv');

        $import = $this->fresh($id);
        self::assertSame([0, 1, 1], [$import->created(), $import->updated(), $import->failed()]);
        self::assertSame([3 => [$this->fresh($id)->options()->matchKey ?? '' => 'import.error.duplicate_in_file']], array_filter($this->errorsOf($import)));
        self::assertSame(['Renamed A'], $this->names());

        self::mockTime('2026-10-01 12:00:00');
        $this->service(UndoImport::class)->undo($id);
        $this->worker->run();

        $import = $this->fresh($id);
        self::assertSame(ImportState::Undone, $import->state());
        self::assertSame([1, 0], [$import->reverted(), $import->changedAfterImport()]);
        $one = $this->partyNamed('Example One');
        self::assertSame('Praha', $this->details()->billingAddress($one)?->address()->city);
    }

    #[DataProvider('keysNamingOneParty')]
    public function testTwoRowsNamingOneNewPartyCreateItOnceInAddMissing(string $header, string $first, string $second): void
    {
        $id = $this->checkedAndRun("Firma,{$header},Ulice,Město,PSČ\nNew A,{$first},Example Street 2,Brno,602 00\nNew B,{$second},Example Street 3,Ostrava,702 00\n", 'new.csv', ImportMode::AddMissing);

        $import = $this->fresh($id);
        self::assertSame([1, 1], [$import->created(), $import->failed()]);
        self::assertSame(['New A'], $this->names());

        self::mockTime('2026-10-01 12:00:00');
        $this->service(UndoImport::class)->undo($id);
        $this->worker->run();

        self::assertSame([ImportState::Undone, 1], [$this->fresh($id)->state(), $this->fresh($id)->reverted()]);
        self::assertSame([], $this->names());
    }

    public function testReimportingTheSameFileChangesNothingAndLinksTheContactPersonOnce(): void
    {
        $this->checkedAndRun((string) file_get_contents(self::FIXTURE));
        $entries = $this->rowCount('SELECT COUNT(*) FROM audit_entry');

        $second = $this->checkedAndRun((string) file_get_contents(self::FIXTURE), 'idoklad-again.csv');

        $import = $this->fresh($second);
        // Two rows have a registration number to match by; the others are created again, as in the source.
        self::assertSame(1, $import->updated());
        $company = $this->partyNamed('Example Company s.r.o.');
        self::assertCount(1, $this->details()->contactLinks($company));
        self::assertCount(2, $this->details()->emails($company));
        // The matched company: no entry for it or for anything that belongs to it.
        $companyEntries = 'SELECT COUNT(*) FROM audit_entry WHERE correlation_id = ? AND (entity_class = ? AND entity_id = ? OR entity_class = ?)';
        self::assertSame(0, $this->rowCount($companyEntries, ['import:'.$second, Party::class, $company->id(), ContactLink::class]));
        self::assertGreaterThan($entries, $this->rowCount('SELECT COUNT(*) FROM audit_entry'));
    }

    private function checkedAndRun(string $csv, string $name = 'contacts.csv', ImportMode $mode = ImportMode::Update): string
    {
        $id = $this->upload($csv, $name);
        $this->configure($id, $mode);
        $this->service(CheckImport::class)->check($id);
        $this->worker->run();
        $this->service(RunImport::class)->run($id);
        $this->worker->run();

        return $id;
    }

    private function upload(string $csv, string $name = 'contacts.csv'): string
    {
        $path = $this->dir.'/'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $csv);
        $id = $this->service(StartImport::class)->start(PartyImportTarget::KEY, $path, $name, '019a0000-0000-7000-8000-000000000001');
        $this->worker->run();

        return $id;
    }

    private function configure(string $id, ImportMode $mode): void
    {
        $import = $this->fresh($id);
        $options = new ImportOptions($mode, null, $import->options()->matchKey, BlankCells::Keep, DateOrder::DayMonthYear, DecimalSeparator::Comma, null);
        $this->service(ConfigureImport::class)->configure($id, $import->mapping(), $options);
    }

    private function fresh(string $id): Import
    {
        return $this->service(Imports::class)->findFresh($id) ?? throw new \LogicException('No such import.');
    }

    /**
     * @return array<int, array<string, string>> row number => field => message key
     */
    private function errorsOf(Import $import): array
    {
        $errors = [];
        foreach ($this->service(Imports::class)->rowsOfChunk($import, 0) as $row) {
            foreach ($row->errors() as $error) {
                $errors[$row->rowNumber()][$error->field] = $error->messageKey;
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $parameters
     */
    private function rowCount(string $sql, array $parameters = []): int
    {
        $count = $this->em->getConnection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function partyNamed(string $name): Party
    {
        $this->em->clear();
        $party = $this->em->getRepository(Party::class)->findOneBy(['displayName' => $name]);
        self::assertInstanceOf(Party::class, $party, $name);

        return $party;
    }

    /**
     * @return list<string> the names of the active parties that are not contact persons
     */
    private function names(): array
    {
        $this->em->clear();
        $names = [];
        foreach ($this->service(PartyQuery::class)->search('', null, false, 'name', 1)->rows as $row) {
            $names[] = $row->displayName;
        }

        return array_values(array_filter($names, static fn (string $name): bool => !str_starts_with($name, 'Ing.')));
    }

    private function details(): PartyDetails
    {
        return $this->service(PartyDetails::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
