<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Application;

use App\Import\Application\CheckImport;
use App\Import\Application\ConfigureImport;
use App\Import\Application\ImportRefused;
use App\Import\Application\Imports;
use App\Import\Application\StartImport;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\BlankCells;
use App\Import\Domain\DateOrder;
use App\Import\Domain\DecimalSeparator;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportOptions;
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
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PipelineTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string HEADER = "Code;Name;Email\n";
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
        $em = $container->get('doctrine.orm.entity_manager');
        $tenants = $container->get(TenantContext::class);
        $this->imports = $container->get(Imports::class);
        $target = $container->get(TargetRegistry::class)->get(FakeTarget::KEY);
        self::assertInstanceOf(FakeTarget::class, $target);
        $this->target = $target;
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->worker = new RunMessages($transport, $container->get(MessageBusInterface::class));

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->dir = sys_get_temp_dir().'/foresi-pipeline-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->tenant = TenantId::generate();
        $tenants->switchTo($this->tenant);
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

    public function testAnAmbiguousKeyAndAKeyRepeatedInTheFileAreErrorsInTheCheck(): void
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
