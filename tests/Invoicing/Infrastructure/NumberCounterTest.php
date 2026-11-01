<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * The counter on the database the suite runs on, and on real parallel processes:
 * always on a file SQLite database, and on PostgreSQL when the suite runs there.
 */
final class NumberCounterTest extends KernelTestCase
{
    private const string ALLOCATE = 'tests/Invoicing/Infrastructure/Support/allocate.php';

    private EntityManagerInterface $em;
    private NumberCounter $counter;
    private SeriesCatalog $catalog;
    private LegalEntity $firm;
    private ?string $sqliteFile = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->counter = self::getContainer()->get(NumberCounter::class);
        $this->catalog = self::getContainer()->get(SeriesCatalog::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
        $this->em->persist($this->firm);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (null !== $this->sqliteFile) {
            foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
                if (is_file($this->sqliteFile.$suffix)) {
                    unlink($this->sqliteFile.$suffix);
                }
            }
        }
        parent::tearDown();
    }

    public function testTheFirstNumberOfAYearIsOne(): void
    {
        $series = $this->series();

        self::assertSame('20260001', $this->counter->next($series, $this->date('2026-10-31')));
        self::assertSame('20260002', $this->counter->next($series, $this->date('2026-11-01')));
    }

    public function testANewYearStartsAgain(): void
    {
        $series = $this->series();
        $this->counter->next($series, $this->date('2026-12-31'));

        self::assertSame('20270001', $this->counter->next($series, $this->date('2027-01-01')));
        self::assertSame('20260002', $this->counter->next($series, $this->date('2026-12-31')), 'a late document of the old year continues it');
    }

    public function testASeriesWithoutAYearNeverResets(): void
    {
        $series = $this->series('{NNNN}');
        $this->counter->next($series, $this->date('2026-12-31'));

        self::assertSame('0002', $this->counter->next($series, $this->date('2027-01-01')));
    }

    public function testAStartAppliesToTheNextNumberOfItsYearOnly(): void
    {
        $series = $this->series();
        $series->startAt(90, '2026');

        self::assertSame('20260090', $this->counter->peek($series, $this->date('2026-10-31')));
        self::assertSame('20260090', $this->counter->next($series, $this->date('2026-10-31')));
        self::assertSame('20260091', $this->counter->next($series, $this->date('2026-10-31')));
        self::assertSame('20270001', $this->counter->next($series, $this->date('2027-01-01')));
    }

    public function testAStartAboveTheCounterMovesIt(): void
    {
        $series = $this->series();
        $this->counter->next($series, $this->date('2026-10-31'));
        $series->startAt(90, '2026');

        self::assertSame('20260090', $this->counter->peek($series, $this->date('2026-10-31')));
        self::assertSame('20260090', $this->counter->next($series, $this->date('2026-10-31')));
    }

    public function testAStartBelowTheCounterNeverReusesANumber(): void
    {
        $series = $this->series();
        foreach (range(1, 3) as $_) {
            $this->counter->next($series, $this->date('2026-10-31'));
        }
        $series->startAt(2, '2026');

        self::assertSame('20260004', $this->counter->peek($series, $this->date('2026-10-31')));
        self::assertSame('20260004', $this->counter->next($series, $this->date('2026-10-31')));
    }

    public function testAdvancingMovesTheCounterUp(): void
    {
        $series = $this->series();

        $this->counter->advanceTo($series, $this->date('2026-10-31'), 89);
        self::assertSame('20260090', $this->counter->next($series, $this->date('2026-10-31')));

        $this->counter->advanceTo($series, $this->date('2026-10-31'), 95);
        self::assertSame('20260096', $this->counter->next($series, $this->date('2026-10-31')));
    }

    public function testAdvancingIsANoOpBelowTheCounter(): void
    {
        $series = $this->series();
        $this->counter->advanceTo($series, $this->date('2026-10-31'), 89);

        $this->counter->advanceTo($series, $this->date('2026-10-31'), 12);

        self::assertSame(89, $this->counter->last($series, '2026'));
    }

    public function testAdvancingToNothingCreatesNoCounter(): void
    {
        $series = $this->series();

        $this->counter->advanceTo($series, $this->date('2026-10-31'), 0);

        self::assertSame(0, $this->rows());
        self::assertFalse($this->catalog->hasDocuments($series));
    }

    public function testPeekWritesNothing(): void
    {
        $series = $this->series();

        self::assertSame('20260001', $this->counter->peek($series, $this->date('2026-10-31')));
        self::assertSame('20260001', $this->counter->peek($series, $this->date('2026-10-31')));
        self::assertSame(0, $this->rows());
    }

    public function testPeekWorksOnASeriesNotStoredYet(): void
    {
        self::assertSame('20260001', $this->counter->peek($this->catalog->defaultFor($this->firm), $this->date('2026-10-31')));
    }

    public function testARolledBackTransactionReturnsTheNumber(): void
    {
        $series = $this->series();
        $work = self::getContainer()->get(TransactionalWork::class);
        $date = $this->date('2026-10-31');

        try {
            $work->run(function () use ($series, $date): never {
                self::assertSame('20260001', $this->counter->next($series, $date));

                throw new \DomainException('Refused.');
            });
        } catch (\DomainException) {
        }

        self::assertSame('20260001', $this->counter->next($series, $date));
    }

    public function testSeriesCountSeparately(): void
    {
        $first = $this->series();
        $second = $this->series('Z{YYYY}{NNNN}', 'Advance');
        $this->counter->next($first, $this->date('2026-10-31'));

        self::assertSame('Z20260001', $this->counter->next($second, $this->date('2026-10-31')));
    }

    public function testAnotherTenantSeesNoCounter(): void
    {
        $series = $this->series();
        $this->counter->next($series, $this->date('2026-10-31'));

        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());

        self::assertSame(0, $this->counter->last($series, '2026'));
    }

    public function testASeriesHasDocumentsOnceANumberLeftTheCounter(): void
    {
        $series = $this->series();
        self::assertFalse($this->catalog->hasDocuments($series));

        $this->counter->next($series, $this->date('2026-10-31'));

        self::assertTrue($this->catalog->hasDocuments($series));
    }

    public function testAStartIsRecordedInTheAuditTrail(): void
    {
        $series = $this->series();
        $series->startAt(90, '2026');
        $this->em->flush();

        $changes = $this->em->getConnection()->fetchFirstColumn('SELECT changes FROM audit_entry WHERE entity_id = :id AND action = :action', ['id' => $series->id(), 'action' => 'updated']);

        self::assertCount(1, $changes);
        self::assertIsString($changes[0]);
        self::assertStringContainsString('90', $changes[0]);
    }

    public function testTheCatalogListsTheActiveSeriesOfTheFirm(): void
    {
        $active = $this->series('{YYYY}{NNNN}', 'Standard');
        $archived = $this->series('A{YYYY}{NNNN}', 'Archived');
        $archived->archive();
        $this->em->flush();

        self::assertSame([$active], $this->catalog->forFirm($this->firm));
        self::assertSame($archived, $this->catalog->get($archived->id()));
    }

    public function testTheCatalogFindsNoSeriesOfAnotherTenant(): void
    {
        $series = $this->series();
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());

        $this->expectException(\App\Invoicing\Application\UnknownSeries::class);

        $this->catalog->get($series->id());
    }

    public function testWithoutADefaultTheStandardSeriesIsOfferedUnsaved(): void
    {
        $series = $this->catalog->defaultFor($this->firm);

        self::assertSame(Series::STANDARD, $series->name());
        self::assertSame('{YYYY}{NNNN}', $series->format()->toString());
        self::assertTrue($series->isDefault());
        self::assertFalse($this->em->contains($series));
    }

    public function testTheStoredDefaultWins(): void
    {
        $series = $this->series();
        $this->catalog->makeDefault($series);
        $this->em->flush();

        self::assertSame($series, $this->catalog->defaultFor($this->firm));
    }

    public function testMakingADefaultReleasesTheOldOne(): void
    {
        $old = $this->series();
        $new = $this->series('Z{YYYY}{NNNN}', 'Advance');
        $this->catalog->makeDefault($old);
        $this->em->flush();

        $this->catalog->makeDefault($new);
        $this->em->flush();

        self::assertFalse($old->isDefault());
        self::assertTrue($new->isDefault());
    }

    public function testTheDatabaseRefusesASecondDefault(): void
    {
        $first = $this->series();
        $second = $this->series('Z{YYYY}{NNNN}', 'Advance');
        $first->markDefault();
        $second->markDefault();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    public function testTheDatabaseRefusesATakenName(): void
    {
        $this->series('{YYYY}{NNNN}', 'Standard');

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->series('Z{NNNN}', 'Standard');
    }

    #[DataProvider('databases')]
    public function testParallelProcessesGetEveryNumberOnce(string $kind): void
    {
        [$url, $series, $tenant] = $this->seeded($kind);
        $startAt = microtime(true) + 2.5;

        $processes = array_map(fn (): Process => $this->start($url, $series, $tenant, 25, $startAt), range(1, 8));
        $numbers = $this->collect($processes);

        self::assertCount(200, array_unique($numbers));
        self::assertSame(range(1, 200), $this->sortedSequences($numbers));
    }

    #[DataProvider('databases')]
    public function testRolledBackNumbersComeBackUnderLoad(string $kind): void
    {
        [$url, $series, $tenant] = $this->seeded($kind);
        $startAt = microtime(true) + 2.5;

        $processes = array_map(fn (): Process => $this->start($url, $series, $tenant, 25, $startAt, 5), range(1, 8));
        $numbers = $this->collect($processes);

        // Every fifth transaction of each process is rolled back: 20 of 25 stay, without a gap.
        self::assertSame(range(1, 160), $this->sortedSequences($numbers));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function databases(): iterable
    {
        yield 'file SQLite' => ['sqlite'];
        if (str_starts_with(self::suiteUrl(), 'postgres')) {
            yield 'PostgreSQL' => ['postgresql'];
        }
    }

    private static function suiteUrl(): string
    {
        $url = $_SERVER['DATABASE_URL'] ?? '';

        return \is_string($url) ? $url : '';
    }

    /**
     * @return array{string, string, string} the database URL, the series and its tenant
     */
    private function seeded(string $kind): array
    {
        $url = self::suiteUrl();
        if ('sqlite' === $kind) {
            $this->sqliteFile = sys_get_temp_dir().'/foresi-counter-'.bin2hex(random_bytes(4)).'.db';
            $url = 'sqlite:///'.$this->sqliteFile;
        }

        $seed = $this->process($url, ['seed']);
        $seed->mustRun();
        [$series, $tenant] = explode(' ', trim($seed->getOutput()));

        return [$url, $series, $tenant];
    }

    private function start(string $url, string $series, string $tenant, int $count, float $startAt, int $rollBackEvery = 0): Process
    {
        $process = $this->process($url, [$series, $tenant, (string) $count, \sprintf('%.6F', $startAt), (string) $rollBackEvery]);
        $process->start();

        return $process;
    }

    /**
     * @param list<string> $arguments
     */
    private function process(string $url, array $arguments): Process
    {
        $root = self::getContainer()->getParameter('kernel.project_dir');

        return new Process([\PHP_BINARY, self::ALLOCATE, ...$arguments], $root, ['DATABASE_URL' => $url, 'APP_ENV' => 'test'], null, 120);
    }

    /**
     * Waits for all of them and proves they really ran at the same time: every
     * process began its work before any of them finished.
     *
     * @param list<Process> $processes
     *
     * @return list<string>
     */
    private function collect(array $processes): array
    {
        $numbers = [];
        $lastBegan = 0.0;
        $firstEnded = \PHP_FLOAT_MAX;
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $lines = explode("\n", trim($process->getOutput()));
            [$first, $last] = explode(' ', array_pop($lines));
            $lastBegan = max($lastBegan, (float) $first);
            $firstEnded = min($firstEnded, (float) $last);
            array_push($numbers, ...$lines);
        }
        self::assertLessThan($firstEnded, $lastBegan, 'the processes did not overlap');

        return $numbers;
    }

    /**
     * @param list<string> $numbers
     *
     * @return list<int>
     */
    private function sortedSequences(array $numbers): array
    {
        $format = SeriesFormat::fromString('{YYYY}{NNNN}');
        $sequences = array_map(static fn (string $number): int => $format->sequenceIn($number, new \DateTimeImmutable('2026-10-31')) ?? 0, $numbers);
        sort($sequences);

        return $sequences;
    }

    private function series(string $format = '{YYYY}{NNNN}', string $name = 'Standard'): Series
    {
        $series = new Series($this->firm, $name, SeriesFormat::fromString($format), DocumentKind::Invoice);
        $this->catalog->add($series);
        $this->em->flush();

        return $series;
    }

    private function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    private function rows(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        return \count($connection->fetchFirstColumn('SELECT id FROM series_counter'));
    }
}
