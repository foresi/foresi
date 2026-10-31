<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Application;

use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Import\Application\CancelImport;
use App\Import\Application\CheckImport;
use App\Import\Application\ConfigureImport;
use App\Import\Application\ImportErrorFile;
use App\Import\Application\ImportRefused;
use App\Import\Application\Imports;
use App\Import\Application\Message\CheckImportChunk;
use App\Import\Application\Message\ReadImportFile;
use App\Import\Application\Message\RunImportChunk;
use App\Import\Application\RunImport;
use App\Import\Application\Source\SourceFormat;
use App\Import\Application\StartImport;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Application\UndoImport;
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
use App\Import\Domain\MatchReason;
use App\Import\Domain\ReplaceScope;
use App\Import\Domain\RowOutcome;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Import\Support\FakeTarget;
use App\Tests\Import\Support\RunMessages;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PipelineTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string HEADER = "Code;Name;Email\n";

    private EntityManagerInterface $em;
    private TenantContext $tenants;
    private Imports $imports;
    private FakeTarget $target;
    private RunMessages $worker;
    private string $dir;
    private TenantId $tenant;

    protected function setUp(): void
    {
        self::mockTime('2026-10-01 10:00:00');
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->tenants = $container->get(TenantContext::class);
        $this->imports = $container->get(Imports::class);
        $target = $container->get(TargetRegistry::class)->get(FakeTarget::KEY);
        self::assertInstanceOf(FakeTarget::class, $target);
        $this->target = $target;
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->worker = new RunMessages($transport, $container->get(MessageBusInterface::class));

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->dir = sys_get_temp_dir().'/foresi-pipeline-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->tenant = TenantId::generate();
        $this->tenants->switchTo($this->tenant);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
        parent::tearDown();
    }

    public function testReadingStagesTheRowsInChunksAndProposesTheMapping(): void
    {
        $id = $this->upload($this->rows(1001));

        $import = $this->fresh($id);
        self::assertSame(ImportState::Mapping, $import->state());
        self::assertSame(1001, $import->total());
        self::assertSame(3, $import->chunks());
        self::assertSame(['Code', 'Name', 'Email'], $import->headers());
        self::assertSame([0 => 'code', 1 => 'name', 2 => 'email'], $import->mapping()->columns);
        self::assertSame([MatchReason::SameName, MatchReason::SameName, MatchReason::SameName], array_map(static fn (\App\Import\Domain\ColumnProposal $p): MatchReason => $p->reason, $import->proposals()));
        self::assertSame('code', $import->options()->matchKey);
        self::assertSame(';', $import->sourceSettings()['delimiter']);
        $rows = $this->imports->rowsOfChunk($import, 2);
        self::assertCount(1, $rows);
        self::assertSame(1002, $rows[0]->rowNumber());
        self::assertSame(['C1001', 'Name 1001', 'person1001@example.com'], $rows[0]->raw());
        self::assertCount(500, $this->imports->rowsOfChunk($import, 0));
        self::assertSame($this->tenant->toString(), $rows[0]->tenantId()->toString());
    }

    public function testAnUnreadableFileFailsTheImportWithTheReason(): void
    {
        $id = $this->upload(self::HEADER);

        $import = $this->fresh($id);
        self::assertSame(ImportState::Failed, $import->state());
        self::assertSame('import.source.rejected.empty', $import->failureMessageKey());
    }

    public function testAddMissingCreatesNewRecordsAndSkipsExistingOnes(): void
    {
        $this->target->add(['code' => 'C1', 'name' => 'Old 1', 'email' => null]);
        $id = $this->upload($this->rows(3));
        $this->configure($id, ImportMode::AddMissing);
        $this->check($id);

        $checked = $this->fresh($id);
        self::assertSame(ImportState::Checked, $checked->state());
        self::assertSame([2, 0, 0, 1, 0, 0, 3], $this->counters($checked));
        self::assertSame([], $this->target->writes);

        $this->runImport($id);

        $import = $this->fresh($id);
        self::assertSame(ImportState::Finished, $import->state());
        self::assertSame([2, 0, 0, 1, 0, 0, 3], $this->counters($import));
        self::assertSame(1, $import->chunksDone());
        self::assertSame(['Name 2', 'Name 3', 'Old 1'], $this->target->activeNames());
        self::assertSame(2, $this->target->count('create'));
        self::assertSame(0, $this->target->count('update'));
    }

    public function testUpdateOverwritesMatchedRecordsAndCreatesTheOthers(): void
    {
        $this->target->add(['code' => 'C1', 'name' => 'Old 1', 'email' => 'old@example.com']);
        $id = $this->upload("Code;Name;Email\nC1;New 1;\nC2;Name 2;person2@example.com\n");
        $this->configure($id, ImportMode::Update);
        $this->check($id);
        $this->runImport($id);

        $import = $this->fresh($id);
        self::assertSame(ImportState::Finished, $import->state());
        self::assertSame([1, 1, 0, 0, 0, 0, 2], $this->counters($import));
        self::assertSame(['Name 2', 'New 1'], $this->target->activeNames());
        // Blank cells keep the value by default.
        self::assertSame('old@example.com', $this->target->records[1]['values']['email']);
        $row = $this->imports->rowsOfChunk($import, 0)[0];
        self::assertSame(RowOutcome::Update, $row->outcome());
        self::assertSame(1, $row->recordId());
        self::assertSame(['values' => ['code' => 'C1', 'name' => 'Old 1', 'email' => 'old@example.com'], 'archived' => false], $row->before());
        self::assertTrue($row->processedInRun());
    }

    public function testEveryWriteCarriesTheImportCorrelation(): void
    {
        $id = $this->upload($this->rows(2));
        $this->configure($id, ImportMode::Update);
        $this->check($id);
        $this->runImport($id);

        self::assertSame([['create', 1, 'import:'.$id], ['create', 2, 'import:'.$id]], $this->target->writes);
    }

    public function testAnAmbiguousKeyAndAKeyRepeatedInTheFileAreErrors(): void
    {
        $this->target->add(['code' => 'C5', 'name' => 'First', 'email' => null]);
        $this->target->add(['code' => 'C5', 'name' => 'Second', 'email' => null]);
        $id = $this->upload("Code;Name;Email\nC5;Five;\nC7;Seven;\nc7;Seven again;\n;No key;\n");
        $this->configure($id, ImportMode::Update);
        $this->check($id);

        $import = $this->fresh($id);
        self::assertSame([2, 0, 0, 0, 0, 2, 4], $this->counters($import));
        $rows = $this->imports->rowsOfChunk($import, 0);
        self::assertSame([RowOutcome::Error, RowOutcome::Create, RowOutcome::Error, RowOutcome::Create], array_map(static fn (ImportRow $row): ?RowOutcome => $row->outcome(), $rows));
        self::assertSame('import.error.ambiguous_match', $rows[0]->errors()[0]->messageKey);
        self::assertSame(['value' => 'C5', 'count' => 2], $rows[0]->errors()[0]->parameters);
        self::assertSame('import.error.duplicate_in_file', $rows[2]->errors()[0]->messageKey);
        self::assertSame(['value' => 'c7'], $rows[2]->errors()[0]->parameters);

        $this->runImport($id);

        self::assertSame(ImportState::FinishedWithErrors, $this->fresh($id)->state());
        self::assertSame([2, 0, 0, 0, 0, 2, 4], $this->counters($this->fresh($id)));
        self::assertSame(['First', 'No key', 'Second', 'Seven'], $this->target->activeNames());
    }

    public function testAnArchivedMatchIsRestoredByUpdateAndSkippedByAddMissing(): void
    {
        $archived = $this->target->add(['code' => 'C1', 'name' => 'Archived', 'email' => null], archived: true);

        $skip = $this->upload($this->rows(1));
        $this->configure($skip, ImportMode::AddMissing);
        $this->check($skip);
        $this->runImport($skip);
        self::assertSame([0, 0, 0, 1, 0, 0, 1], $this->counters($this->fresh($skip)));
        self::assertTrue($this->target->records[$archived]['archived']);

        $restore = $this->upload($this->rows(1));
        $this->configure($restore, ImportMode::Update);
        $this->check($restore);
        self::assertSame([0, 0, 1, 0, 0, 0, 1], $this->counters($this->fresh($restore)));
        $this->runImport($restore);

        self::assertSame([0, 0, 1, 0, 0, 0, 1], $this->counters($this->fresh($restore)));
        self::assertFalse($this->target->records[$archived]['archived']);
        self::assertSame('Name 1', $this->target->records[$archived]['values']['name']);
    }

    public function testAFullReplaceOfTheProfileArchivesOnlyWhatEarlierRunsOfItWrote(): void
    {
        $first = $this->upload($this->rows(3));
        $this->configure($first, ImportMode::Update, newProfile: 'Fake records');
        $this->check($first);
        $this->runImport($first);
        $profileId = (int) $this->fresh($first)->profile()?->id();
        $manual = $this->target->add(['code' => 'M1', 'name' => 'Manual', 'email' => null]);

        $second = $this->upload($this->rows(1));
        $this->configure($second, ImportMode::FullReplace, ReplaceScope::Profile, profileId: $profileId);
        $this->check($second);

        $checked = $this->fresh($second);
        self::assertSame(2, $checked->archiveCandidates());
        self::assertTrue($checked->needsArchiveConfirmation());

        $this->runImport($second, 2);

        $import = $this->fresh($second);
        self::assertSame(ImportState::Finished, $import->state());
        self::assertSame(2, $import->archived());
        self::assertSame(['Manual', 'Name 1'], $this->target->activeNames());
        self::assertFalse($this->target->records[$manual]['archived']);
        self::assertSame([['archive', 2, 'import:'.$second], ['archive', 3, 'import:'.$second]], \array_slice($this->target->writes, -2));
    }

    public function testAFullReplaceOfAllRecordsNeedsTheTypedNumber(): void
    {
        $this->target->add(['code' => 'X1', 'name' => 'Old X1', 'email' => null]);
        $this->target->add(['code' => 'X2', 'name' => 'Old X2', 'email' => null]);
        $this->target->add(['code' => 'X3', 'name' => 'Old X3', 'email' => null]);
        // The wrong file: it matches none of the existing records.
        $id = $this->upload($this->rows(2));
        $this->configure($id, ImportMode::FullReplace, ReplaceScope::All);
        $this->check($id);
        self::assertSame(3, $this->fresh($id)->archiveCandidates());

        foreach ([null, 2] as $confirmation) {
            try {
                $this->runImport($id, $confirmation);
                self::fail('A full replace must not archive without the typed number.');
            } catch (ImportRefused $refused) {
                self::assertSame('import.refused.archive_not_confirmed', $refused->reason);
                self::assertSame(['count' => 3], $refused->parameters);
            }
            self::assertSame(ImportState::Checked, $this->fresh($id)->state());
            self::assertSame([], $this->target->writes);
        }

        $this->runImport($id, 3);

        self::assertSame(3, $this->fresh($id)->archived());
        self::assertSame(['Name 1', 'Name 2'], $this->target->activeNames());
    }

    public function testARecordWithAnErrorRowIsNotArchivedAsMissing(): void
    {
        $this->target->add(['code' => 'C1', 'name' => 'Kept', 'email' => null]);
        $id = $this->upload("Code;Name;Email\nC1;Kept;not-an-address\n");
        $this->configure($id, ImportMode::FullReplace, ReplaceScope::All);
        $this->check($id);

        self::assertSame(0, $this->fresh($id)->archiveCandidates());
        $this->runImport($id);
        self::assertSame(['Kept'], $this->target->activeNames());
    }

    public function testARedeliveredChunkWritesNothingTwice(): void
    {
        $id = $this->upload($this->rows(1200));
        $this->configure($id, ImportMode::Update);
        $this->check($id);
        $this->runImport($id);

        $runs = array_values(array_filter($this->worker->handled(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof RunImportChunk));
        self::assertCount(3, $runs);
        foreach ([...$runs, $runs[2]] as $envelope) {
            $this->worker->redeliver($envelope);
        }
        $checks = array_values(array_filter($this->worker->handled(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof CheckImportChunk));
        $this->worker->redeliver($checks[1]);
        $this->worker->run();

        $import = $this->fresh($id);
        self::assertSame(ImportState::Finished, $import->state());
        self::assertSame([1200, 0, 0, 0, 0, 0, 1200], $this->counters($import));
        self::assertSame(3, $import->chunksDone());
        self::assertSame(1200, $this->target->count('create'));
        self::assertCount(1200, $this->target->records);
    }

    public function testAChunkDeliveredTwiceDuringTheCheckIsCountedOnce(): void
    {
        $id = $this->upload($this->rows(1200));
        $this->configure($id, ImportMode::Update);
        $this->container(CheckImport::class)->check($id);
        $this->worker->run(1);
        $first = $this->worker->last();
        $this->worker->redeliver($first);

        self::assertSame(500, $this->fresh($id)->processed());
        self::assertSame(1, $this->fresh($id)->chunksDone());
        $this->worker->run();

        $import = $this->fresh($id);
        self::assertSame(ImportState::Checked, $import->state());
        self::assertSame(1200, $import->created());
        self::assertSame(1200, $import->processed());
    }

    public function testTheRunStopsWhenMoreThanHalfOfTheFirstChunksFail(): void
    {
        $csv = self::HEADER;
        for ($i = 1; $i <= 1500; ++$i) {
            $csv .= \sprintf("C%d;%s %d;\n", $i, $i <= 501 ? 'INVALID' : 'Name', $i);
        }
        $id = $this->upload($csv);
        $this->configure($id, ImportMode::Update);
        $this->check($id);
        $this->runImport($id);

        $import = $this->fresh($id);
        self::assertSame(ImportState::Stopped, $import->state());
        self::assertSame(1000, $import->processed());
        self::assertSame(501, $import->failed());
        self::assertSame(499, $import->created());
        self::assertSame(499, $this->target->count('create'));
        self::assertSame(2, $import->chunksDone());
    }

    public function testASingleChunkWithMostlyErrorsStopsToo(): void
    {
        $id = $this->upload("Code;Name;Email\nC1;INVALID;\nC2;INVALID;\nC3;Fine;\n");
        $this->configure($id, ImportMode::Update);
        $this->check($id);
        $this->runImport($id);

        self::assertSame(ImportState::Stopped, $this->fresh($id)->state());
    }

    public function testHalfOfTheRowsFailingIsNotAboveTheThreshold(): void
    {
        $id = $this->upload("Code;Name;Email\nC1;INVALID;\nC2;Fine;\n");
        $this->configure($id, ImportMode::Update);
        $this->check($id);
        $this->runImport($id);

        self::assertSame(ImportState::FinishedWithErrors, $this->fresh($id)->state());
    }

    public function testDataChangedBetweenCheckAndRunIsReadAgain(): void
    {
        $id = $this->upload($this->rows(2));
        $this->configure($id, ImportMode::Update);
        $this->check($id);
        self::assertSame(2, $this->fresh($id)->created());
        $existing = $this->target->add(['code' => 'C1', 'name' => 'Made meanwhile', 'email' => null]);

        $this->runImport($id);

        $import = $this->fresh($id);
        self::assertSame([1, 1, 0, 0, 0, 0, 2], $this->counters($import));
        self::assertSame('Name 1', $this->target->records[$existing]['values']['name']);
        self::assertCount(2, $this->target->records);
    }

    public function testARowTheTargetRejectsIsAnErrorAndTheChunkGoesOn(): void
    {
        $id = $this->upload("Code;Name;Email\nC1;Name 1;\nC2;FAIL here;\nC3;Name 3;\n");
        $this->configure($id, ImportMode::Update);
        $this->check($id);
        $this->runImport($id);

        $import = $this->fresh($id);
        self::assertSame(ImportState::FinishedWithErrors, $import->state());
        self::assertSame([2, 0, 0, 0, 0, 1, 3], $this->counters($import));
        $failed = $this->imports->rowsOfChunk($import, 0)[1];
        self::assertSame(RowOutcome::Error, $failed->outcome());
        self::assertSame('import.error.write_failed', $failed->errors()[0]->messageKey);
        self::assertNull($failed->recordId());
        self::assertTrue($failed->processedInRun());
    }

    public function testAWriteThatFailsInTheDatabaseIsAnErrorAndTheChunkGoesOn(): void
    {
        $id = $this->checked("Code;Name;Email\nC1;Name 1;\nC2;Broken;\nC3;Name 3;\n");
        $em = $this->em;
        $imports = $this->imports;
        // The target stores something the database refuses (a second row 3 of this import), so the flush fails and closes the manager.
        $this->target->onCreate = static function (array $values) use ($em, $imports, $id): void {
            if ('Broken' === $values['name']) {
                $em->persist(new ImportRow($imports->find($id) ?? throw new \LogicException(), 3, 0, []));
            }
        };

        $this->runImport($id);

        $import = $this->fresh($id);
        self::assertSame(ImportState::FinishedWithErrors, $import->state());
        self::assertSame([2, 0, 0, 0, 0, 1, 3], $this->counters($import));
        self::assertSame('import.error.write_failed', $this->imports->rowsOfChunk($import, 0)[1]->errors()[0]->messageKey);
        self::assertTrue($this->em->isOpen());
    }

    public function testAStoppedRunCanBeUndone(): void
    {
        $id = $this->checked("Code;Name;Email\nC1;INVALID;\nC2;INVALID;\nC3;Fine;\n");
        $this->runImport($id);
        self::assertSame(ImportState::Stopped, $this->fresh($id)->state());

        $this->container(UndoImport::class)->undo($id);
        $this->worker->run();

        self::assertSame(ImportState::Undone, $this->fresh($id)->state());
        self::assertSame(1, $this->fresh($id)->reverted());
        self::assertSame([], $this->target->activeNames());
    }

    public function testASecondImportOfTheSameTargetWaitsInTheQueue(): void
    {
        $first = $this->checked($this->rows(2));
        $second = $this->checked("Code;Name;Email\nD1;Other 1;\n");

        $this->container(RunImport::class)->run($first);
        $this->container(RunImport::class)->run($second);

        self::assertSame(ImportState::Running, $this->fresh($first)->state());
        self::assertSame(ImportState::Queued, $this->fresh($second)->state());
        self::assertSame([], array_filter($this->worker->pending(), static fn (object $message): bool => $message instanceof RunImportChunk && $message->importId === $second));

        $this->worker->run();

        self::assertSame(ImportState::Finished, $this->fresh($first)->state());
        self::assertSame(ImportState::Finished, $this->fresh($second)->state());
        self::assertSame(['Name 1', 'Name 2', 'Other 1'], $this->target->activeNames());
    }

    public function testRunningTwiceIsRefused(): void
    {
        $id = $this->checked($this->rows(1));
        $this->container(RunImport::class)->run($id);

        $this->expectExceptionObject(new ImportRefused('import.refused.not_checked'));
        $this->container(RunImport::class)->run($id);
    }

    public function testImportsOfTwoTenantsRunIndependently(): void
    {
        $first = $this->checked($this->rows(2));
        $other = TenantId::generate();
        $this->tenants->switchTo($other);
        $second = $this->checked($this->rows(1));
        $this->tenants->switchTo($this->tenant);
        $this->container(RunImport::class)->run($first);

        $this->tenants->switchTo($other);
        $this->container(RunImport::class)->run($second);
        self::assertSame(ImportState::Running, $this->fresh($second)->state());

        $this->worker->run();

        $done = $this->fresh($second);
        self::assertSame(ImportState::Finished, $done->state());
        self::assertSame(['Name 1'], $this->target->activeNames());
        $this->tenants->switchTo($this->tenant);
        $done = $this->fresh($first);
        self::assertSame(ImportState::Finished, $done->state());
        self::assertSame(['Name 1', 'Name 2'], $this->target->activeNames());
    }

    public function testAMessageCannotReachAnImportOfAnotherTenant(): void
    {
        $id = $this->checked($this->rows(1));
        $this->tenants->switchTo(TenantId::generate());

        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new RunImportChunk($id, 0));
        $bus->dispatch(new ReadImportFile($id));
        $this->worker->run();

        $this->tenants->switchTo($this->tenant);
        self::assertSame(ImportState::Checked, $this->fresh($id)->state());
        self::assertSame([], $this->target->writes);
    }

    public function testUndoRevertsWhatTheRunWroteAndLeavesRecordsChangedSince(): void
    {
        $kept = $this->target->add(['code' => 'C1', 'name' => 'Old 1', 'email' => null]);
        $edited = $this->target->add(['code' => 'C2', 'name' => 'Old 2', 'email' => null]);
        $id = $this->checked($this->rows(3), ImportMode::Update);
        $this->runImport($id);
        self::assertSame(['Name 1', 'Name 2', 'Name 3'], $this->target->activeNames());

        // Someone edits a record after the import.
        $this->target->records[$edited]['values']['name'] = 'Edited by hand';
        $this->em->persist(new AuditEntry(FakeTarget::class, (string) $edited, AuditAction::Updated, ['name' => ['Name 2', 'Edited by hand']], 1, new \DateTimeImmutable('2026-10-01 10:30:00')));
        $this->em->flush();
        self::mockTime('2026-10-20 10:00:00');

        $this->container(UndoImport::class)->undo($id);
        $this->worker->run();

        $import = $this->fresh($id);
        self::assertSame(ImportState::Undone, $import->state());
        self::assertSame(2, $import->reverted());
        self::assertSame(1, $import->changedAfterImport());
        self::assertSame(['Edited by hand', 'Old 1'], $this->target->activeNames());
        self::assertSame('Old 1', $this->target->records[$kept]['values']['name']);
        $rows = $this->imports->rowsOfChunk($import, 0);
        self::assertSame([RowOutcome::Reverted, RowOutcome::ChangedAfterImport, RowOutcome::Reverted], array_map(static fn (ImportRow $row): ?RowOutcome => $row->outcome(), $rows));
        $reverts = array_values(array_filter($this->target->writes, static fn (array $write): bool => 'revert' === $write[0]));
        self::assertSame([[$kept, 'import-undo:'.$id], [3, 'import-undo:'.$id]], array_map(static fn (array $write): array => [$write[1], $write[2]], $reverts));
    }

    public function testUndoRestoresWhatAFullReplaceArchivedAndArchivesWhatItRestored(): void
    {
        $missing = $this->target->add(['code' => 'X1', 'name' => 'Missing', 'email' => null]);
        $archived = $this->target->add(['code' => 'C1', 'name' => 'Archived', 'email' => null], archived: true);
        $id = $this->checked($this->rows(1), ImportMode::FullReplace, ReplaceScope::All);
        $this->runImport($id, 1);
        self::assertSame([0, 0, 1, 0, 1, 0, 1], $this->counters($this->fresh($id)));
        self::assertSame(['Name 1'], $this->target->activeNames());

        $this->container(UndoImport::class)->undo($id);
        $this->worker->run();

        self::assertSame(ImportState::Undone, $this->fresh($id)->state());
        self::assertSame(2, $this->fresh($id)->reverted());
        self::assertSame(['Missing'], $this->target->activeNames());
        self::assertTrue($this->target->records[$archived]['archived']);
        self::assertSame('Archived', $this->target->records[$archived]['values']['name']);
        self::assertFalse($this->target->records[$missing]['archived']);
    }

    public function testARedeliveredUndoChunkRevertsNothingTwice(): void
    {
        $id = $this->checked($this->rows(2), ImportMode::Update);
        $this->runImport($id);
        $this->container(UndoImport::class)->undo($id);
        $this->worker->run();
        $last = $this->worker->last();

        $this->worker->redeliver($last);

        self::assertSame(2, $this->fresh($id)->reverted());
        self::assertSame(2, $this->target->count('revert'));
    }

    public function testUndoIsRefusedAfterThirtyDays(): void
    {
        $id = $this->checked($this->rows(1), ImportMode::Update);
        $this->runImport($id);
        self::mockTime('2026-10-31 10:00:01');

        $this->expectExceptionObject(new ImportRefused('import.refused.undo_expired'));
        $this->container(UndoImport::class)->undo($id);
    }

    public function testUndoIsRefusedOnceTheRowsArePurged(): void
    {
        $id = $this->checked($this->rows(1), ImportMode::Update);
        $this->runImport($id);
        $this->em->getConnection()->executeStatement('UPDATE import SET file_key = NULL WHERE id = ?', [$id]);

        $this->expectExceptionObject(new ImportRefused('import.refused.undo_expired'));
        $this->container(UndoImport::class)->undo($id);
    }

    public function testUndoIsRefusedWhileAnotherImportOfTheTargetRuns(): void
    {
        $done = $this->checked($this->rows(1), ImportMode::Update);
        $this->runImport($done);
        $running = $this->checked("Code;Name;Email\nD1;Other;\n");
        $this->container(RunImport::class)->run($running);

        $this->expectExceptionObject(new ImportRefused('import.refused.busy'));
        $this->container(UndoImport::class)->undo($done);
    }

    public function testTheErrorFileHoldsTheFailedRowsWithTheirMessages(): void
    {
        $id = $this->upload("Code;Name;Email\nC1;Name 1;\n=1+1;Name 2;broken\nC3;INVALID;\n");
        $this->configure($id, ImportMode::Update);
        $this->check($id);
        $path = $this->dir.'/errors.csv';

        $format = $this->container(ImportErrorFile::class)->write($id, 'en', $path);

        self::assertSame(SourceFormat::Csv, $format);
        self::assertStringStartsWith("\xEF\xBB\xBF", (string) file_get_contents($path));
        $options = new CsvReaderOptions();
        $options->FIELD_DELIMITER = ';';
        $reader = new CsvReader($options);
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        self::assertSame([
            ['Code', 'Name', 'Email', 'Error'],
            ["'=1+1", 'Name 2', 'broken', '"broken" is not a valid e-mail address. (column Email)'],
            ['C3', 'INVALID', '', '"INVALID" is not a value we know. Map it to one of the allowed values. (column Name)'],
        ], $rows);
    }

    public function testCancellingACheckedImportWritesNothing(): void
    {
        $id = $this->checked($this->rows(2));

        $this->container(CancelImport::class)->cancel($id);

        self::assertSame(ImportState::Cancelled, $this->fresh($id)->state());
        try {
            $this->container(RunImport::class)->run($id);
            self::fail('A cancelled import must not run.');
        } catch (ImportRefused $refused) {
            self::assertSame('import.refused.not_checked', $refused->reason);
        }
        $this->worker->run();
        self::assertSame([], $this->target->writes);
        self::assertSame(['Name'], array_unique(array_map(static fn (ImportRow $row): string => substr($row->raw()[1], 0, 4), $this->imports->rowsOfChunk($this->fresh($id), 0))));
    }

    public function testARunningImportCannotBeCancelled(): void
    {
        $id = $this->checked($this->rows(1));
        $this->container(RunImport::class)->run($id);

        $this->expectExceptionObject(new ImportRefused('import.refused.not_cancellable'));
        $this->container(CancelImport::class)->cancel($id);
    }

    public function testTheProfileRemembersTheMappingOnlyAfterTheRun(): void
    {
        $id = $this->upload("Kód;Jméno;Pošta\nC1;Name 1;\n");
        $this->container(ConfigureImport::class)->configure($id, new Mapping([0 => 'code', 1 => 'name', 2 => 'email']), $this->options(ImportMode::Update), newProfileName: 'Fake records');
        $profile = $this->fresh($id)->profile();
        self::assertInstanceOf(ImportProfile::class, $profile);
        self::assertFalse($profile->isRemembered());

        $this->check($id);
        $this->runImport($id);

        $this->em->clear();
        $remembered = $this->container(\App\Import\Application\ProfileRepository::class)->find((int) $profile->id());
        self::assertInstanceOf(ImportProfile::class, $remembered);
        self::assertTrue($remembered->isRemembered());
        self::assertSame([0 => 'code', 1 => 'name', 2 => 'email'], $remembered->mapping()->columns);

        $next = $this->upload("Kód;Jméno;Pošta\nC2;Name 2;\n");
        $proposals = $this->fresh($next)->proposals();
        self::assertSame([MatchReason::Remembered, MatchReason::Remembered, MatchReason::Remembered], array_map(static fn (\App\Import\Domain\ColumnProposal $p): MatchReason => $p->reason, $proposals));
        self::assertSame($remembered->id(), $this->fresh($next)->profile()?->id());
    }

    public function testConfiguringRefusesAnUnmappedRequiredField(): void
    {
        $id = $this->upload($this->rows(1));

        $this->expectExceptionObject(new ImportRefused('import.refused.required_unmapped', ['fields' => 'name']));
        $this->container(ConfigureImport::class)->configure($id, new Mapping([0 => 'code', 1 => null, 2 => 'email']), $this->options(ImportMode::Update));
    }

    public function testAFullReplaceOfTheProfileNeedsAProfile(): void
    {
        $id = $this->upload($this->rows(1));

        $this->expectExceptionObject(new ImportRefused('import.refused.profile_required'));
        $this->configure($id, ImportMode::FullReplace, ReplaceScope::Profile);
    }

    private function upload(string $csv, ?int $profileId = null): int
    {
        $path = $this->dir.'/'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $csv);
        $id = $this->container(StartImport::class)->start(FakeTarget::KEY, $path, 'records.csv', 1, $profileId);
        $this->worker->run();

        return $id;
    }

    private function checked(string $csv, ImportMode $mode = ImportMode::Update, ?ReplaceScope $scope = null): int
    {
        $id = $this->upload($csv);
        $this->configure($id, $mode, $scope);
        $this->check($id);

        return $id;
    }

    private function configure(int $id, ImportMode $mode, ?ReplaceScope $scope = null, ?int $profileId = null, ?string $newProfile = null): void
    {
        $this->container(ConfigureImport::class)->configure($id, $this->fresh($id)->mapping(), $this->options($mode, $scope), $profileId, $newProfile);
    }

    private function options(ImportMode $mode, ?ReplaceScope $scope = null): ImportOptions
    {
        return new ImportOptions($mode, $scope, null, BlankCells::Keep, DateOrder::DayMonthYear, DecimalSeparator::Comma, null);
    }

    private function check(int $id): void
    {
        $this->container(CheckImport::class)->check($id);
        $this->worker->run();
    }

    private function runImport(int $id, ?int $confirmation = null): void
    {
        $this->container(RunImport::class)->run($id, $confirmation);
        $this->worker->run();
    }

    private function fresh(int $id): Import
    {
        return $this->imports->findFresh($id) ?? throw new \LogicException('No such import.');
    }

    /**
     * @return list<int> created, updated, restored, skipped, archived, failed, processed
     */
    private function counters(Import $import): array
    {
        return [$import->created(), $import->updated(), $import->restored(), $import->skipped(), $import->archived(), $import->failed(), $import->processed()];
    }

    private function rows(int $count): string
    {
        $csv = self::HEADER;
        for ($i = 1; $i <= $count; ++$i) {
            $csv .= \sprintf("C%d;Name %d;person%d@example.com\n", $i, $i, $i);
        }

        return $csv;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function container(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
