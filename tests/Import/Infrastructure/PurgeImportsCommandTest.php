<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure;

use App\Import\Application\Imports;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMissingRecord;
use App\Import\Domain\ImportRow;
use App\Import\Domain\ImportState;
use App\Import\Infrastructure\Console\PurgeImportsCommand;
use App\Import\Infrastructure\LocalImportFiles;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class PurgeImportsCommandTest extends KernelTestCase
{
    private const string NOW = '2026-11-15 12:00:00';

    private EntityManagerInterface $em;
    private TenantContext $context;
    private Imports $imports;
    private LocalImportFiles $files;
    private string $base;
    private CommandTester $tester;

    /** @var array<string, int> import ids by file key */
    private array $importIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->context = $container->get(TenantContext::class);
        $this->imports = $container->get(Imports::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->base = sys_get_temp_dir().'/foresi-purge-'.bin2hex(random_bytes(6));
        mkdir($this->base);
        $this->files = new LocalImportFiles($this->base.'/imports');
        $command = new PurgeImportsCommand($this->em->getConnection(), $this->files, new MockClock(self::NOW));
        $application = new Application();
        $application->addCommand($command);
        $this->tester = new CommandTester($application->find('app:import:purge'));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->base);
        parent::tearDown();
    }

    public function testRemovesFilesAndRowsOfImportsThatEndedMoreThan30DaysAgo(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $old = $this->finished($tenant, '2026-10-16 11:59:59', 2);
        $this->em->clear();

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertFileDoesNotExist($this->pathOf($old));
        self::assertSame(0, $this->rowCount($old));
        $kept = $this->imports->find($this->importIds[$old]);
        self::assertNotNull($kept);
        self::assertNull($kept->fileKey());
        self::assertSame(ImportState::Finished, $kept->state());
        self::assertSame(3, $kept->created());
        self::assertSame(2, $kept->total());
        self::assertStringContainsString('1 imports', $this->tester->getDisplay());
        self::assertStringContainsString('1 files and 2 rows', $this->tester->getDisplay());
    }

    public function testRemovesTheMissingRecordsOfAFullReplaceToo(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $old = $this->finished($tenant, '2026-10-16 11:59:59', 1);
        $import = $this->imports->find($this->importIds[$old]);
        self::assertNotNull($import);
        $this->em->persist(new ImportMissingRecord($import, 1, 42));
        $this->em->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM import_row');
        $this->files->delete($old);
        $this->em->getConnection()->executeStatement('UPDATE import SET file_key = NULL');
        $this->em->clear();

        $this->tester->execute([]);

        self::assertEquals(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM import_missing_record'));
        self::assertStringContainsString('1 imports', $this->tester->getDisplay());
    }

    public function testKeepsImportsThatEndedExactly30DaysAgoOrLater(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $exactly = $this->finished($tenant, '2026-10-16 12:00:00', 1);
        $recent = $this->finished($tenant, '2026-11-14 12:00:00', 1);
        $this->em->clear();

        $this->tester->execute([]);

        foreach ([$exactly, $recent] as $key) {
            self::assertFileExists($this->pathOf($key));
            self::assertSame(1, $this->rowCount($key));
        }
        self::assertStringContainsString('0 imports', $this->tester->getDisplay());
    }

    public function testKeepsImportsThatHaveNotEnded(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $running = $this->stage($tenant, 1);
        foreach ([ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running] as $state) {
            $running['import']->moveTo($state, new \DateTimeImmutable('2026-01-01'));
        }
        $this->imports->save();
        $this->em->clear();

        $this->tester->execute([]);

        self::assertFileExists($this->pathOf($running['key']));
        self::assertSame(1, $this->rowCount($running['key']));
    }

    #[DataProvider('abandoned')]
    public function testAnImportLeftWaitingForMoreThan30DaysIsCancelledAndPurged(ImportState $waiting): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $staged = $this->stage($tenant, 2, '2026-10-16 11:59:59');
        $this->moveThrough($staged['import'], $waiting);
        $this->imports->save();
        $this->em->clear();

        $this->tester->execute([]);

        self::assertFileDoesNotExist($this->pathOf($staged['key']));
        self::assertSame(0, $this->rowCount($staged['key']));
        $import = $this->imports->find($this->importIds[$staged['key']]);
        self::assertNotNull($import);
        self::assertSame(ImportState::Cancelled, $import->state());
        self::assertNull($import->fileKey());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $import->finishedAt());
        self::assertStringContainsString('1 imports (1 cancelled)', $this->tester->getDisplay());

        $this->tester->execute([]);
        self::assertStringContainsString('0 imports (0 cancelled)', $this->tester->getDisplay());
    }

    /**
     * @return iterable<string, array{ImportState}>
     */
    public static function abandoned(): iterable
    {
        foreach ([ImportState::Uploaded, ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked] as $state) {
            yield $state->value => [$state];
        }
    }

    #[DataProvider('abandoned')]
    public function testAWaitingImportYoungerThan30DaysIsKept(ImportState $waiting): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $staged = $this->stage($tenant, 1, '2026-10-16 12:00:00');
        $this->moveThrough($staged['import'], $waiting);
        $this->imports->save();
        $this->em->clear();

        $this->tester->execute([]);

        self::assertFileExists($this->pathOf($staged['key']));
        self::assertSame(1, $this->rowCount($staged['key']));
        self::assertSame($waiting, $this->imports->find($this->importIds[$staged['key']])?->state());
    }

    public function testAQueuedImportIsLeftToItsWorkerHoweverOld(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $staged = $this->stage($tenant, 1);
        $this->moveThrough($staged['import'], ImportState::Queued);
        $this->imports->save();
        $this->em->clear();

        $this->tester->execute([]);

        self::assertFileExists($this->pathOf($staged['key']));
        self::assertSame(ImportState::Queued, $this->imports->find($this->importIds[$staged['key']])?->state());
    }

    public function testAnUndoInProgressIsNotPurged(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $staged = $this->stage($tenant, 1);
        $this->advance($staged['import'], ImportState::Finished, '2026-01-01');
        $staged['import']->moveTo(ImportState::Undoing);
        $this->imports->save();
        $this->em->clear();

        $this->tester->execute([]);

        self::assertFileExists($this->pathOf($staged['key']));
        self::assertSame(1, $this->rowCount($staged['key']));
    }

    #[DataProvider('endings')]
    public function testEveryEndedStateIsPurged(ImportState $ending): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $staged = $this->stage($tenant, 1);
        $this->advance($staged['import'], $ending, '2026-01-01');
        $this->imports->save();
        $this->em->clear();

        $this->tester->execute([]);

        self::assertFileDoesNotExist($this->pathOf($staged['key']));
        self::assertSame(0, $this->rowCount($staged['key']));
    }

    /**
     * @return iterable<string, array{ImportState}>
     */
    public static function endings(): iterable
    {
        foreach ([ImportState::Finished, ImportState::FinishedWithErrors, ImportState::Stopped, ImportState::Failed, ImportState::Cancelled, ImportState::Undone] as $state) {
            yield $state->value => [$state];
        }
    }

    public function testPurgesAllTenantsAndASecondRunDoesNothing(): void
    {
        $first = TenantId::generate();
        $this->context->switchTo($first);
        $one = $this->finished($first, '2026-01-01 00:00:00', 1);
        $second = TenantId::generate();
        $this->context->switchTo($second);
        $two = $this->finished($second, '2026-01-01 00:00:00', 1);
        $this->context->reset();

        $this->tester->execute([]);
        self::assertStringContainsString('2 imports', $this->tester->getDisplay());
        self::assertFileDoesNotExist($this->pathOf($one));
        self::assertFileDoesNotExist($this->pathOf($two));

        $this->tester->execute([]);
        self::assertStringContainsString('0 imports', $this->tester->getDisplay());
    }

    public function testAnImportWhoseFileIsAlreadyGoneStillLosesItsRows(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $key = $this->finished($tenant, '2026-01-01 00:00:00', 2);
        $this->files->delete($key);
        $this->em->clear();

        $this->tester->execute([]);

        self::assertSame(0, $this->rowCount($key));
    }

    private function pathOf(string $fileKey): string
    {
        return $this->base.'/imports/'.$fileKey;
    }

    private function rowCount(string $fileKey): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM import_row WHERE import_id = ?', [$this->importIds[$fileKey]]);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return array{import: Import, key: string}
     */
    private function stage(TenantId $tenant, int $rows, string $createdAt = '2026-01-01'): array
    {
        $source = $this->base.'/upload.tmp';
        file_put_contents($source, "name\nExample Company\n");
        $key = $this->files->store($tenant, $source, 'contacts.csv');
        $import = new Import('party', $key, 'contacts.csv', ['format' => 'csv'], 1, new \DateTimeImmutable($createdAt));
        $this->imports->add($import);
        $staged = [];
        for ($number = 1; $number <= $rows; ++$number) {
            $staged[] = new ImportRow($import, $number, 0, ['Example Company']);
        }
        $this->imports->addRows($staged);
        $this->importIds[$key] = (int) $import->id();

        return ['import' => $import, 'key' => $key];
    }

    private function moveThrough(Import $import, ImportState $target): void
    {
        $path = [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued];
        foreach ($path as $state) {
            if (ImportState::Uploaded === $target) {
                return;
            }
            $import->moveTo($state, new \DateTimeImmutable('2026-01-01'));
            if ($state === $target) {
                return;
            }
        }
    }

    private function advance(Import $import, ImportState $ending, string $at): void
    {
        $date = new \DateTimeImmutable($at);
        foreach ([ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running] as $state) {
            $import->moveTo($state, $date);
        }
        if (ImportState::Undone === $ending) {
            $import->moveTo(ImportState::Finished, $date);
            $import->moveTo(ImportState::Undoing, $date);
            $import->moveTo(ImportState::Undone, $date);
        } else {
            $import->moveTo($ending, $date);
        }
    }

    private function finished(TenantId $tenant, string $at, int $rows): string
    {
        $staged = $this->stage($tenant, $rows);
        $staged['import']->countRows($rows, 1);
        $staged['import']->recordChunk(['create' => 3]);
        $this->advance($staged['import'], ImportState::Finished, $at);
        $this->imports->save();

        return $staged['key'];
    }
}
