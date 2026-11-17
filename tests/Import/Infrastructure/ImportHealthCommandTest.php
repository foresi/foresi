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
use App\Instance\Application\Schedule\TenantDirectory;
use App\Instance\Application\SoleTenant;
use App\Instance\Application\TenantContext;
use App\Instance\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
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

    private FailingLock $lock;

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
        $this->lock = new FailingLock();
        $directory = new class implements TenantDirectory {
            public function ids(): array
            {
                return [TenantId::generate()];
            }

            public function zones(): array
            {
                return [];
            }

            public function zone(TenantId $tenant): \DateTimeZone
            {
                return new \DateTimeZone('UTC');
            }
        };
        $clock = new MockClock(self::NOW);
        $gate = new ImportGate($this->imports, $this->lock, $this->jobs, $clock);
        $application = new Application();
        $application->addCommand(new ImportHealthCommand($this->em->getConnection(), $this->context, new SoleTenant($directory), $this->imports, $gate, $clock, new NullLogger()));
        $this->tester = new CommandTester($application->find('app:import:health'));
    }

    public function testAStalledRunningImportFailsAndTheNextQueuedImportStarts(): void
    {
        $stalled = $this->importIn(ImportState::Running, self::STALLED);
        $waiting = $this->importIn(ImportState::Queued, '2026-11-14 00:00:00', 3);

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Failed 1 stalled imports', $this->tester->getDisplay());
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
        // Started a day ago, but a chunk finished a minute ago.
        $busy = $this->importIn(ImportState::Running, '2026-11-15 11:59:00', 1, '2026-11-14 12:00:00');

        $this->tester->execute([]);

        self::assertStringContainsString('Failed 0 stalled imports', $this->tester->getDisplay());
        self::assertSame(ImportState::Running, $this->imports->find($busy)?->state());
        self::assertSame([], $this->jobs->sent);
    }

    public function testAnImportIdleForExactlyTheLimitIsNotStalledYet(): void
    {
        $import = $this->importIn(ImportState::Running, self::AT_THE_LIMIT);

        $this->tester->execute([]);

        self::assertSame(ImportState::Running, $this->imports->find($import)?->state());
    }

    #[DataProvider('working')]
    public function testEveryWorkingStateCanStall(ImportState $state): void
    {
        $import = $this->importIn($state, self::STALLED);

        $this->tester->execute([]);

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
        $import = $this->importIn($state, '2026-01-01 00:00:00');

        $this->tester->execute([]);

        self::assertSame($state, $this->imports->find($import)?->state());
    }

    /**
     * @return iterable<string, array{ImportState}>
     */
    public static function idle(): iterable
    {
        foreach ([ImportState::Uploaded, ImportState::Mapping, ImportState::Checked, ImportState::Finished, ImportState::Failed] as $state) {
            yield $state->value => [$state];
        }
    }

    public function testFailsStalledImportsOfEveryTargetAndStartsTheQueueOfItsOwnTargetOnly(): void
    {
        $one = $this->importIn(ImportState::Running, self::STALLED);
        $two = $this->importIn(ImportState::Undoing, self::STALLED, target: 'other');
        $otherWaiting = $this->importIn(ImportState::Queued, '2026-11-14 00:00:00', target: 'other');
        $partyWaiting = $this->importIn(ImportState::Queued, '2026-11-14 00:00:00');

        $this->tester->execute([]);

        self::assertStringContainsString('Failed 2 stalled imports', $this->tester->getDisplay());
        self::assertSame(ImportState::Failed, $this->imports->find($one)?->state());
        self::assertSame(ImportState::Failed, $this->imports->find($two)?->state());
        self::assertSame(ImportState::Running, $this->imports->find($otherWaiting)?->state());
        self::assertSame(ImportState::Running, $this->imports->find($partyWaiting)?->state());
    }

    public function testAnImportWithoutProgressTimeIsJudgedByItsStart(): void
    {
        $import = $this->importIn(ImportState::Running, self::STALLED);
        $this->em->getConnection()->executeStatement('UPDATE import SET progressed_at = NULL, started_at = ?', [self::STALLED]);
        $this->em->clear();

        $this->tester->execute([]);

        self::assertSame(ImportState::Failed, $this->imports->find($import)?->state());
    }

    public function testAQueueWithoutARunningImportIsStartedEvenWhenNothingStalled(): void
    {
        $older = $this->importIn(ImportState::Queued, '2026-11-14 00:00:00', 2);
        $newer = $this->importIn(ImportState::Queued, '2026-11-14 01:00:00');

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Failed 0 stalled imports, released 1 waiting queues', $this->tester->getDisplay());
        self::assertSame(ImportState::Running, $this->imports->find($older)?->state());
        self::assertSame(ImportState::Queued, $this->imports->find($newer)?->state());
        self::assertEquals([new RunImportChunk($older, 0), new RunImportChunk($older, 1)], $this->jobs->sent);
    }

    public function testAQueueBehindARunningImportIsLeftAlone(): void
    {
        $this->importIn(ImportState::Running, '2026-11-15 11:59:00');
        $waiting = $this->importIn(ImportState::Queued, '2026-11-14 00:00:00');

        $this->tester->execute([]);

        self::assertStringContainsString('released 0 waiting queues', $this->tester->getDisplay());
        self::assertSame(ImportState::Queued, $this->imports->find($waiting)?->state());
    }

    public function testAFailingReleaseDoesNotStopTheOthersAndTheNextRunRepairsIt(): void
    {
        $brokenStalled = $this->importIn(ImportState::Running, self::STALLED, target: 'broken');
        $brokenWaiting = $this->importIn(ImportState::Queued, '2026-11-14 00:00:00', target: 'broken');
        $fineStalled = $this->importIn(ImportState::Running, self::STALLED);
        $fineWaiting = $this->importIn(ImportState::Queued, '2026-11-14 00:00:00');
        $this->lock->failFor = 'broken';

        $status = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertFalse($this->context->has());
        self::assertSame(ImportState::Failed, $this->imports->find($fineStalled)?->state());
        self::assertSame(ImportState::Running, $this->imports->find($fineWaiting)?->state());
        self::assertSame(ImportState::Failed, $this->imports->find($brokenStalled)?->state());
        self::assertSame(ImportState::Queued, $this->imports->find($brokenWaiting)?->state());

        $this->lock->failFor = null;
        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertSame(ImportState::Running, $this->imports->find($brokenWaiting)?->state());
    }

    public function testASecondRunChangesNothing(): void
    {
        $stalled = $this->importIn(ImportState::Running, self::STALLED);
        $waiting = $this->importIn(ImportState::Queued, '2026-11-14 00:00:00');

        $this->tester->execute([]);
        $sent = $this->jobs->sent;
        $this->tester->execute([]);

        self::assertStringContainsString('Failed 0 stalled imports, released 0 waiting queues', $this->tester->getDisplay());
        self::assertSame($sent, $this->jobs->sent);
        self::assertSame(ImportState::Failed, $this->imports->find($stalled)?->state());
        self::assertSame(ImportState::Running, $this->imports->find($waiting)?->state());
    }

    public function testTheTenantIsClearedAfterTheRun(): void
    {
        $this->importIn(ImportState::Running, self::STALLED);

        $this->tester->execute([]);

        self::assertFalse($this->context->has());
    }

    /**
     * @param int $chunks only Queued imports are given chunks
     */
    private function importIn(ImportState $state, string $progressedAt, int $chunks = 1, ?string $startedAt = null, string $target = 'party'): string
    {
        $import = new Import($target, null, 'contacts.csv', ['format' => 'csv'], '019a0000-0000-7000-8000-000000000001', new \DateTimeImmutable('2026-01-01'));
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
        $id = $import->id();
        $this->em->getConnection()->executeStatement(
            'UPDATE import SET progressed_at = ?, started_at = COALESCE(?, started_at) WHERE id = ?',
            [$progressedAt, $startedAt, $id],
        );
        $this->em->clear();

        return $id;
    }
}

/** Runs the work, or throws when the lock name contains the given text. */
final class FailingLock implements ImportLock
{
    public ?string $failFor = null;

    public function synchronized(string $name, callable $work): mixed
    {
        if (null !== $this->failFor && str_contains($name, $this->failFor)) {
            throw new \RuntimeException('The lock store is down.');
        }

        return $work();
    }
}
