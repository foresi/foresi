<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Issue, edit, trash and delivery in real parallel processes, on the database the
 * suite runs on and always on a file SQLite database, as NumberCounterTest does.
 * The processes meet on one invoice per time slot.
 *
 * @phpstan-type Stored array{number: string, headerNet: int, lineNet: int, positions: list<int>, texts: list<string>, trashed: bool, delivered: bool, version: int}
 */
#[Group('race')]
final class InvoiceRaceTest extends KernelTestCase
{
    private const string WORKER = 'tests/Invoicing/Infrastructure/Support/race.php';
    private const int ROUNDS = 8;

    private ?string $sqliteFile = null;

    protected function setUp(): void
    {
        self::bootKernel();
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

    #[DataProvider('databases')]
    public function testParallelIssuesGetDifferentNumbers(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, self::ROUNDS, 1);

        $results = $this->race($url, $ids, ['issue', 'issue', 'issue', 'issue']);

        $numbers = array_merge(...$results);
        self::assertCount(4 * self::ROUNDS, array_unique($numbers), 'every issue got a number of its own');
        foreach ($numbers as $number) {
            self::assertMatchesRegularExpression('/^2026\d{4}$/D', $number);
        }
    }

    #[DataProvider('databases')]
    public function testOfTwoEditsOfOneFormOneWinsAndTheDocumentAddsUp(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, self::ROUNDS, 1);

        [$two, $three] = $this->race($url, $ids, ['edit-two', 'edit-three']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertSame($invoice['lineNet'], $invoice['headerNet'], 'the header and the lines agree');
            self::assertSame(range(0, \count($invoice['positions']) - 1), $invoice['positions']);
            self::assertSame(2, $invoice['version']);
            $outcome = [$two[$round], $three[$round]];
            sort($outcome);
            self::assertSame(['done', 'refused:invoice.problem.changed_meanwhile'], $outcome);
            self::assertSame('done' === $two[$round] ? ['two', 'two'] : ['three', 'three', 'three'], $invoice['texts']);
        }
    }

    #[DataProvider('databases')]
    public function testADoubleClickedSaveStoresOneLineSet(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, self::ROUNDS, 3);

        $this->race($url, $ids, ['edit-one', 'edit-one']);

        foreach ($this->stored($url, $ids) as $invoice) {
            self::assertSame(['one'], $invoice['texts']);
            self::assertSame(10000, $invoice['headerNet']);
            self::assertSame(10000, $invoice['lineNet']);
        }
    }

    #[DataProvider('databases')]
    public function testAnInvoiceIsNeverDeliveredAndTrashed(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, self::ROUNDS, 1);

        [$trash, $deliver] = $this->race($url, $ids, ['trash', 'deliver']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertNotSame($invoice['trashed'], $invoice['delivered'], 'one of them wins');
            self::assertSame($invoice['trashed'] ? ['done', 'refused'] : ['refused', 'done'], [$trash[$round], $deliver[$round]]);
        }
    }

    #[DataProvider('databases')]
    public function testAnEditNeverLandsOnATrashedInvoice(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, self::ROUNDS, 1);

        [$trash, $edit] = $this->race($url, $ids, ['trash', 'edit-two']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertSame('done', $trash[$round]);
            self::assertTrue($invoice['trashed']);
            self::assertSame('done' === $edit[$round] ? ['two', 'two'] : ['seed'], $invoice['texts'], 'an edit either came first or changed nothing');
        }
    }

    #[DataProvider('databases')]
    public function testADeliveredInvoiceIsNeverRenumbered(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, self::ROUNDS, 1);

        [$deliver, $renumber] = $this->race($url, $ids, ['deliver', 'renumber']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertSame('done', $deliver[$round]);
            self::assertTrue($invoice['delivered']);
            self::assertSame('done' === $renumber[$round], str_starts_with($invoice['number'], 'R-'), 'a renumber either came first or changed nothing');
        }
    }

    private static function suiteUrl(): string
    {
        $url = $_SERVER['DATABASE_URL'] ?? '';

        return \is_string($url) ? $url : '';
    }

    /**
     * @return array{string, string} the database URL and the ids as JSON
     */
    private function seeded(string $kind, int $invoices, int $lines): array
    {
        $url = self::suiteUrl();
        if ('sqlite' === $kind) {
            $this->sqliteFile = sys_get_temp_dir().'/foresi-race-'.bin2hex(random_bytes(4)).'.db';
            $url = 'sqlite:///'.$this->sqliteFile;
        }
        $seed = $this->process($url, ['seed', (string) $invoices, (string) $lines]);
        $seed->mustRun();

        return [$url, $seed->getOutput()];
    }

    /**
     * Starts one process per job, and proves they ran at the same time.
     *
     * @param list<string> $jobs
     *
     * @return list<list<string>> the results per job, one per time slot
     */
    private function race(string $url, string $ids, array $jobs): array
    {
        $startAt = \sprintf('%.6F', microtime(true) + 3.0);
        $processes = array_map(fn (string $job): Process => $this->process($url, ['run', $ids, $job, $startAt]), $jobs);
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        $lastBegan = 0.0;
        $firstEnded = \PHP_FLOAT_MAX;
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            /** @var array{results: list<string>, began: float, ended: float} $outcome */
            $outcome = json_decode($process->getOutput(), true, 8, \JSON_THROW_ON_ERROR);
            $results[] = $outcome['results'];
            $lastBegan = max($lastBegan, $outcome['began']);
            $firstEnded = min($firstEnded, $outcome['ended']);
        }
        self::assertLessThan($firstEnded, $lastBegan, 'the processes did not overlap');

        return $results;
    }

    /**
     * @return list<Stored>
     */
    private function stored(string $url, string $ids): array
    {
        $inspect = $this->process($url, ['inspect', $ids]);
        $inspect->mustRun();
        /** @var list<Stored> $stored */
        $stored = json_decode($inspect->getOutput(), true, 8, \JSON_THROW_ON_ERROR);

        return $stored;
    }

    /**
     * @param list<string> $arguments
     */
    private function process(string $url, array $arguments): Process
    {
        $root = self::getContainer()->getParameter('kernel.project_dir');

        return new Process([\PHP_BINARY, self::WORKER, ...$arguments], $root, ['DATABASE_URL' => $url, 'APP_ENV' => 'test'], null, 120);
    }
}
