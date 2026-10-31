<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure;

use App\Import\Application\ImportGate;
use App\Import\Application\ImportJobs;
use App\Import\Application\ImportLock;
use App\Import\Application\Imports;
use App\Import\Application\Message\RunImportChunk;
use App\Import\Domain\Import;
use App\Import\Domain\ImportState;
use App\Import\Infrastructure\Console\ImportHealthCommand;
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

final class ImportHealthCommandTest extends KernelTestCase
{
    private const string NOW = '2026-11-15 12:00:00';
    /** Exactly 4 hours before NOW: not yet stalled. */
    private const string AT_THE_LIMIT = '2026-11-15 08:00:00';
    private const string STALLED = '2026-11-15 07:59:59';

    private EntityManagerInterface $em;
    private TenantContext $context;
    private Imports $imports;
    private CommandTester $tester;

    /** @var ImportJobs&object{sent: list<object>} */
    private object $jobs;

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

        $this->jobs = new class implements ImportJobs {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message): void
            {
                $this->sent[] = $message;
            }
        };
        $lock = new class implements ImportLock {
            public function synchronized(string $name, callable $work): mixed
            {
                return $work();
            }
        };
        $clock = new MockClock(self::NOW);
        $gate = new ImportGate($this->imports, $lock, $this->jobs, $this->context, $clock);
        $application = new Application();
        $application->addCommand(new ImportHealthCommand($this->em->getConnection(), $this->context, $this->imports, $gate, $clock));
        $this->tester = new CommandTester($application->find('app:import:health'));
    }

    public function testAStalledRunningImportFailsAndTheNextQueuedImportStarts(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $stalled = $this->importIn(ImportState::Running, self::STALLED);
        $waiting = $this->importIn(ImportState::Queued, '2026-11-14 00:00:00', 3);
        $this->context->reset();

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Failed 1 stalled imports', $this->tester->getDisplay());
        $this->context->switchTo($tenant);
        $failed = $this->imports->find($stalled);
        self::assertNotNull($failed);
        self::assertSame(ImportState::Failed, $failed->state());
        self::assertSame('import.failure.stalled', $failed->failureMessageKey());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $failed->finishedAt());
        self::assertSame(ImportState::Running, $this->imports->find($waiting)?->state());
        self::assertEquals([new RunImportChunk($waiting, 0), new RunImportChunk($waiting, 1), new RunImportChunk($waiting, 2)], $this->jobs->sent);
    }

    public function testAnImportThatKeepsProgressingIsLeftAlone(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        // Started a day ago, but a chunk finished a minute ago.
        $busy = $this->importIn(ImportState::Running, '2026-11-15 11:59:00', 1, '2026-11-14 12:00:00');
        $this->context->reset();

        $this->tester->execute([]);

        self::assertStringContainsString('Failed 0 stalled imports', $this->tester->getDisplay());
        $this->context->switchTo($tenant);
        self::assertSame(ImportState::Running, $this->imports->find($busy)?->state());
        self::assertSame([], $this->jobs->sent);
    }

    public function testAnImportIdleForExactlyTheLimitIsNotStalledYet(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $import = $this->importIn(ImportState::Running, self::AT_THE_LIMIT);
        $this->context->reset();

        $this->tester->execute([]);

        $this->context->switchTo($tenant);
        self::assertSame(ImportState::Running, $this->imports->find($import)?->state());
    }

    #[DataProvider('working')]
    public function testEveryWorkingStateCanStall(ImportState $state): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $import = $this->importIn($state, self::STALLED);
        $this->context->reset();

        $this->tester->execute([]);

        $this->context->switchTo($tenant);
        self::assertSame(ImportState::Failed, $this->imports->find($import)?->state());
    }

    /**
     * @return iterable<string, array{ImportState}>
     */
    public static function working(): iterable
    {
        foreach ([ImportState::Reading, ImportState::Checking, ImportState::Running, ImportState::Undoing] as $state) {
            yield $state->value => [$state];
        }
    }

    #[DataProvider('idle')]
    public function testImportsThatNoWorkerOwnsAreNotTouched(ImportState $state): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $import = $this->importIn($state, '2026-01-01 00:00:00');
        $this->context->reset();

        $this->tester->execute([]);

        $this->context->switchTo($tenant);
        self::assertSame($state, $this->imports->find($import)?->state());
    }

    /**
     * @return iterable<string, array{ImportState}>
     */
    public static function idle(): iterable
    {
        foreach ([ImportState::Uploaded, ImportState::Mapping, ImportState::Checked, ImportState::Queued, ImportState::Finished, ImportState::Failed] as $state) {
            yield $state->value => [$state];
        }
    }

    public function testWorksAcrossTenantsAndStartsTheQueueOfTheSameTenantOnly(): void
    {
        $first = TenantId::generate();
        $this->context->switchTo($first);
        $one = $this->importIn(ImportState::Running, self::STALLED);
        $second = TenantId::generate();
        $this->context->switchTo($second);
        $two = $this->importIn(ImportState::Undoing, self::STALLED);
        $otherWaiting = $this->importIn(ImportState::Queued, '2026-11-14 00:00:00');
        $this->context->reset();

        $this->tester->execute([]);

        self::assertStringContainsString('Failed 2 stalled imports', $this->tester->getDisplay());
        $this->context->switchTo($first);
        self::assertSame(ImportState::Failed, $this->imports->find($one)?->state());
        $this->context->switchTo($second);
        self::assertSame(ImportState::Failed, $this->imports->find($two)?->state());
        self::assertSame(ImportState::Running, $this->imports->find($otherWaiting)?->state());
    }

    public function testAnImportWithoutProgressTimeIsJudgedByItsStart(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $import = $this->importIn(ImportState::Running, self::STALLED);
        $this->em->getConnection()->executeStatement('UPDATE import SET progressed_at = NULL, started_at = ?', [self::STALLED]);
        $this->em->clear();
        $this->context->reset();

        $this->tester->execute([]);

        $this->context->switchTo($tenant);
        self::assertSame(ImportState::Failed, $this->imports->find($import)?->state());
    }

    public function testTheTenantIsClearedAfterTheRun(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $this->importIn(ImportState::Running, self::STALLED);
        $this->context->reset();

        $this->tester->execute([]);

        self::assertFalse($this->context->has());
    }

    /**
     * @param int $chunks only Queued imports are given chunks
     */
    private function importIn(ImportState $state, string $progressedAt, int $chunks = 1, ?string $startedAt = null): int
    {
        $import = new Import('party', null, 'contacts.csv', ['format' => 'csv'], 1, new \DateTimeImmutable('2026-01-01'));
        $this->imports->add($import);
        $import->countRows($chunks * 10, $chunks);
        $long = new \DateTimeImmutable('2026-01-01');
        $upToQueued = [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued];
        $steps = match ($state) {
            ImportState::Uploaded => [],
            ImportState::Reading => \array_slice($upToQueued, 0, 1),
            ImportState::Mapping => \array_slice($upToQueued, 0, 2),
            ImportState::Checking => \array_slice($upToQueued, 0, 3),
            ImportState::Checked => \array_slice($upToQueued, 0, 4),
            ImportState::Queued => $upToQueued,
            ImportState::Running => [...$upToQueued, ImportState::Running],
            ImportState::Undoing => [...$upToQueued, ImportState::Running, ImportState::Finished, ImportState::Undoing],
            default => [...$upToQueued, ImportState::Running, $state],
        };
        foreach ($steps as $step) {
            $import->moveTo($step, $long);
        }
        $this->imports->save();
        $id = (int) $import->id();
        $this->em->getConnection()->executeStatement(
            'UPDATE import SET progressed_at = ?, started_at = COALESCE(?, started_at) WHERE id = ?',
            [$progressedAt, $startedAt, $id],
        );
        $this->em->clear();

        return $id;
    }
}
