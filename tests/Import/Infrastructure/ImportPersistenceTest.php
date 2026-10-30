<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure;

use App\Import\Application\Imports;
use App\Import\Application\ProfileRepository;
use App\Import\Domain\BlankCells;
use App\Import\Domain\DateOrder;
use App\Import\Domain\DecimalSeparator;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\ImportProfile;
use App\Import\Domain\ImportRow;
use App\Import\Domain\ImportState;
use App\Import\Domain\Mapping;
use App\Import\Domain\ReplaceScope;
use App\Import\Domain\RowError;
use App\Import\Domain\RowOutcome;
use App\Tenancy\Application\NoActiveTenant;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantContext $context;
    private Imports $imports;
    private ProfileRepository $profiles;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->context = $container->get(TenantContext::class);
        $this->imports = $container->get(Imports::class);
        $this->profiles = $container->get(ProfileRepository::class);

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testAnImportWithItsProfileAndRowsRoundTrips(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $options = new ImportOptions(ImportMode::FullReplace, ReplaceScope::All, 'registration_number', BlankCells::Clear, DateOrder::YearMonthDay, DecimalSeparator::Point, 3);
        $mapping = new Mapping([0 => 'name', 1 => null], ['kind' => ['Firma' => 'company']]);
        $profile = new ImportProfile('Contacts', 'party', 'idoklad', $mapping, $options, Mapping::signature(['Name', 'Kind']), new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->profiles->add($profile);
        $import = new Import('party', 'tenant/file', 'contacts.csv', ['format' => 'csv', 'encoding' => 'utf-8', 'delimiter' => ';', 'sheet' => null, 'headerRow' => 1], 7, new \DateTimeImmutable('2026-10-01 10:00:00'));
        $import->readHeaders(['Name', 'Kind']);
        $import->useMapping($mapping, $options, $profile);
        $import->moveTo(ImportState::Reading);
        $import->countRows(2, 1);
        $this->imports->add($import);
        $row = new ImportRow($import, 2, 0, ['Example Company', 'Firma']);
        $row->decide(RowOutcome::Error, [new RowError('kind', 'import.error.unknown_choice', ['value' => 'Firma'])]);
        $row->record(5, ['name' => 'Old']);
        $row->markProcessed();
        $this->imports->addRows([$row, new ImportRow($import, 3, 0, ['Test Person', 'Osoba'])]);
        $this->em->clear();

        $loaded = $this->imports->find((int) $import->id());

        self::assertNotNull($loaded);
        self::assertSame('party', $loaded->targetKey());
        self::assertSame(ImportState::Reading, $loaded->state());
        self::assertTrue($tenant->equals($loaded->tenantId()));
        self::assertSame(['Name', 'Kind'], $loaded->headers());
        self::assertSame('csv', $loaded->sourceSettings()['format']);
        self::assertEquals($mapping, $loaded->mapping());
        self::assertEquals($options, $loaded->options());
        self::assertSame(2, $loaded->total());
        self::assertSame('Contacts', $loaded->profile()?->name());
        self::assertEquals(new \DateTimeImmutable('2026-10-01 10:00:00'), $loaded->createdAt());
        $rows = $this->imports->rowsOfChunk($loaded, 0);
        self::assertCount(2, $rows);
        self::assertSame([2, 3], [$rows[0]->rowNumber(), $rows[1]->rowNumber()]);
        self::assertSame(RowOutcome::Error, $rows[0]->outcome());
        self::assertEquals([new RowError('kind', 'import.error.unknown_choice', ['value' => 'Firma'])], $rows[0]->errors());
        self::assertSame(['Example Company', 'Firma'], $rows[0]->raw());
        self::assertSame(5, $rows[0]->recordId());
        self::assertSame(['name' => 'Old'], $rows[0]->before());
        self::assertTrue($rows[0]->processedInRun());
        self::assertNull($rows[1]->outcome());
        self::assertNull($rows[1]->before());
        self::assertSame([], $this->imports->rowsOfChunk($loaded, 1));
    }

    public function testCountersSurviveALockedUpdate(): void
    {
        $this->context->switchTo(TenantId::generate());
        $import = $this->newImport();
        $import->countRows(4, 2);
        $this->imports->add($import);
        $id = (int) $import->id();
        $this->em->clear();

        $this->em->wrapInTransaction(function () use ($id): void {
            $locked = $this->imports->findLocked($id);
            self::assertNotNull($locked);
            $locked->recordChunk([RowOutcome::Create->value => 2]);
            $this->imports->save();
        });
        $this->em->clear();

        $loaded = $this->imports->find($id);
        self::assertNotNull($loaded);
        self::assertSame([2, 2, 1], [$loaded->created(), $loaded->processed(), $loaded->chunksDone()]);
    }

    public function testALockedUpdateOfAnAlreadyLoadedImportKeepsCountersWrittenMeanwhile(): void
    {
        $this->context->switchTo(TenantId::generate());
        $import = $this->newImport();
        $import->countRows(10, 3);
        $this->imports->add($import);
        $id = (int) $import->id();
        $this->em->clear();
        $loaded = $this->imports->find($id);
        self::assertSame(0, $loaded?->created());

        // Another worker finishes a chunk while this one holds the stale entity.
        $this->em->getConnection()->executeStatement('UPDATE import SET created = 5, processed = 5, chunks_done = 1 WHERE id = ?', [$id]);

        $this->em->wrapInTransaction(function () use ($id): void {
            $locked = $this->imports->findLocked($id);
            self::assertNotNull($locked);
            $locked->recordChunk([RowOutcome::Create->value => 2]);
            $this->imports->save();
        });
        $this->em->clear();

        $after = $this->imports->find($id);
        self::assertNotNull($after);
        self::assertSame([7, 7, 2], [$after->created(), $after->processed(), $after->chunksDone()]);
    }

    public function testStagedRowsBelongToTheTenantOfTheirImport(): void
    {
        $this->context->switchTo(TenantId::generate());
        $import = $this->newImport();
        $this->imports->add($import);
        $this->imports->addRows([new ImportRow($import, 1, 0, ['a'])]);
        $id = (int) $import->id();
        self::assertCount(1, $this->imports->rowsOfChunk($import, 0));

        $this->context->switchTo(TenantId::generate());

        $rows = $this->em->getRepository(ImportRow::class)->findBy(['chunk' => 0]);
        self::assertSame([], $rows);
        self::assertNull($this->imports->find($id));
    }

    public function testARowNumberIsUniquePerImport(): void
    {
        $this->context->switchTo(TenantId::generate());
        $import = $this->newImport();
        $this->imports->add($import);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->imports->addRows([new ImportRow($import, 1, 0, ['a']), new ImportRow($import, 1, 0, ['b'])]);
    }

    public function testImportsAndProfilesBelongToTheirTenant(): void
    {
        $first = TenantId::generate();
        $this->context->switchTo($first);
        $mine = $this->newImport();
        $this->imports->add($mine);
        $this->profiles->add(new ImportProfile('Contacts', 'party', null, new Mapping(), ImportOptions::standard(), 'sig', new \DateTimeImmutable('2026-10-01')));
        $id = (int) $mine->id();

        $this->context->switchTo(TenantId::generate());

        self::assertNull($this->imports->find($id));
        self::assertNull($this->profiles->findByHeaders('party', 'sig'));
        self::assertSame([], $this->profiles->forTarget('party'));
    }

    public function testNothingIsReadOrWrittenWithoutATenant(): void
    {
        $this->context->reset();

        $this->expectException(NoActiveTenant::class);
        $this->imports->add($this->newImport());
    }

    public function testTheNewestProfileForTheHeadersWins(): void
    {
        $this->context->switchTo(TenantId::generate());
        $older = new ImportProfile('Older', 'party', null, new Mapping(), ImportOptions::standard(), 'sig', new \DateTimeImmutable('2026-10-01'));
        $newer = new ImportProfile('Newer', 'party', null, new Mapping(), ImportOptions::standard(), 'sig', new \DateTimeImmutable('2026-10-02'));
        $other = new ImportProfile('Other', 'party', null, new Mapping(), ImportOptions::standard(), 'different', new \DateTimeImmutable('2026-10-03'));
        foreach ([$newer, $older, $other] as $profile) {
            $this->profiles->add($profile);
        }

        self::assertSame('Newer', $this->profiles->findByHeaders('party', 'sig')?->name());
        self::assertNull($this->profiles->findByHeaders('invoice', 'sig'));
        self::assertSame(['Other', 'Newer', 'Older'], array_map(static fn (ImportProfile $profile): string => $profile->name(), $this->profiles->forTarget('party')));
        self::assertSame('Older', $this->profiles->find((int) $older->id())?->name());
    }

    public function testSourceFilesAreStoredOutsideThePublicWebRoot(): void
    {
        $directory = self::getContainer()->getParameter('app.import_files_dir');
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');

        self::assertSame($projectDir.'/var/imports', $directory);
        self::assertStringStartsNotWith($projectDir.'/public', $directory);
    }

    private function newImport(): Import
    {
        return new Import('party', 'tenant/file', 'contacts.csv', ['format' => 'csv', 'encoding' => null, 'delimiter' => null, 'sheet' => null, 'headerRow' => null], 7, new \DateTimeImmutable('2026-10-01'));
    }
}
