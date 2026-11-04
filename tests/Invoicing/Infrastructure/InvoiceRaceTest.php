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
    private const float START_DELAY = 3.0;
    private const int ATTEMPTS = 3;

    /** @var list<string> */
    private array $sqliteFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->sqliteFiles as $file) {
            foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
                if (is_file($file.$suffix)) {
                    unlink($file.$suffix);
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
        [, , $results] = $this->raced($kind, self::ROUNDS, 1, ['issue', 'issue', 'issue', 'issue']);

        $numbers = array_merge(...$results);
        self::assertCount(4 * self::ROUNDS, array_unique($numbers), 'every issue got a number of its own');
        foreach ($numbers as $number) {
            self::assertMatchesRegularExpression('/^2026\d{4}$/D', $number);
        }
    }

    #[DataProvider('databases')]
    public function testOfTwoEditsOfOneFormOneWinsAndTheDocumentAddsUp(string $kind): void
    {
        [$url, $ids, [$two, $three]] = $this->raced($kind, self::ROUNDS, 1, ['edit-two', 'edit-three']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            $this->assertOneWon($two[$round], $three[$round], $invoice);
            self::assertSame('done' === $two[$round] ? ['two', 'two'] : ['three', 'three', 'three'], $invoice['texts']);
        }
    }

    #[DataProvider('databases')]
    public function testAShrinkAndARewriteOfOneFormNeverMix(string $kind): void
    {
        [$url, $ids, [$shrink, $rewrite]] = $this->raced($kind, self::ROUNDS, 3, ['edit-one', 'edit-three']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            $this->assertOneWon($shrink[$round], $rewrite[$round], $invoice);
            self::assertSame('done' === $shrink[$round] ? ['one'] : ['three', 'three', 'three'], $invoice['texts']);
        }
    }

    #[DataProvider('databases')]
    public function testADoubleClickedSaveCountsOnce(string $kind): void
    {
        [$url, $ids, [$first, $second]] = $this->raced($kind, self::ROUNDS, 3, ['edit-one', 'edit-one']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            $this->assertOneWon($first[$round], $second[$round], $invoice);
            self::assertSame(['one'], $invoice['texts']);
            self::assertSame(10000, $invoice['headerNet']);
        }
    }

    #[DataProvider('databases')]
    public function testAnInvoiceIsNeverDeliveredAndTrashed(string $kind): void
    {
        [$url, $ids, [$trash, $deliver]] = $this->raced($kind, self::ROUNDS, 1, ['trash', 'deliver']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertNotSame($invoice['trashed'], $invoice['delivered'], 'one of them wins');
            self::assertSame($invoice['trashed'] ? ['done', 'refused'] : ['refused', 'done'], [$trash[$round], $deliver[$round]]);
            self::assertSame(2, $invoice['version'], 'only the winner saved');
        }
    }

    #[DataProvider('databases')]
    public function testAnEditNeverLandsOnATrashedInvoice(string $kind): void
    {
        [$url, $ids, [$trash, $edit]] = $this->raced($kind, self::ROUNDS, 1, ['trash', 'edit-two']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertSame('done', $trash[$round]);
            self::assertTrue($invoice['trashed']);
            if ('done' === $edit[$round]) {
                self::assertSame(['two', 'two'], $invoice['texts']);
                self::assertSame(3, $invoice['version'], 'the edit came first, and the trash after it saw that');
            } else {
                self::assertSame(['seed'], $invoice['texts'], 'a refused edit changed nothing');
                self::assertSame(2, $invoice['version']);
            }
        }
    }

    #[DataProvider('databases')]
    public function testADeliveredInvoiceIsNeverRenumbered(string $kind): void
    {
        [$url, $ids, [$deliver, $renumber]] = $this->raced($kind, self::ROUNDS, 1, ['deliver', 'renumber']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertSame('done', $deliver[$round]);
            self::assertTrue($invoice['delivered']);
            if ('done' === $renumber[$round]) {
                self::assertStringStartsWith('R-', $invoice['number']);
                self::assertSame(4, $invoice['version'], 'the renumber came first, and the delivery after it saw that');
            } else {
                self::assertStringStartsNotWith('R-', $invoice['number'], 'a refused renumber changed nothing');
                self::assertSame(2, $invoice['version']);
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function heldRows(): iterable
    {
        foreach (self::databases() as $name => [$kind]) {
            yield $name.', renumber waits for a delivery' => [$kind, 'delivered', 'renumber', 'refused:invoice.problem.number_locked'];
            yield $name.', edit waits for the trash' => [$kind, 'trashed', 'edit-two', 'refused:invoice.problem.trashed'];
            yield $name.', trash waits for a delivery' => [$kind, 'delivered', 'trash', 'refused'];
        }
    }

    #[DataProvider('heldRows')]
    public function testAServiceWaitsForAHeldRowAndSeesItsChange(string $kind, string $change, string $job, string $refusal): void
    {
        [$url, $ids] = $this->seeded($kind, 1, 1);
        $startAt = microtime(true) + self::START_DELAY;
        $holder = $this->process($url, ['hold', $ids, $change, \sprintf('%.6F', $startAt)]);
        $service = $this->process($url, ['run', $ids, $job, \sprintf('%.6F', $startAt + 0.4)]);
        $holder->start();
        $service->start();
        $holder->wait();
        $service->wait();

        self::assertTrue($holder->isSuccessful(), $holder->getErrorOutput().$holder->getOutput());
        self::assertTrue($service->isSuccessful(), $service->getErrorOutput().$service->getOutput());
        /** @var array{results: list<string>, began: list<float>, ended: list<float>} $outcome */
        $outcome = json_decode($service->getOutput(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame($refusal, $outcome['results'][0]);
        self::assertGreaterThan(0.4, $outcome['ended'][0] - $outcome['began'][0], 'it waited for the row');
        $invoice = $this->stored($url, $ids)[0];
        self::assertSame(['seed'], $invoice['texts']);
        self::assertSame(1, $invoice['version']);
        self::assertStringStartsNotWith('R-', $invoice['number']);
        self::assertSame('delivered' === $change, $invoice['delivered']);
        self::assertSame('trashed' === $change, $invoice['trashed']);
    }

    #[DataProvider('databases')]
    public function testADocumentNeverShowsHalfASave(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, 1, 1);
        $startAt = microtime(true) + self::START_DELAY;
        $editor = $this->process($url, ['churn', $ids, \sprintf('%.6F', $startAt), '30']);
        $reader = $this->process($url, ['read', $ids, \sprintf('%.6F', $startAt), '2']);
        $editor->start();
        $reader->start();
        $editor->wait();
        $reader->wait();

        self::assertTrue($editor->isSuccessful(), $editor->getErrorOutput().$editor->getOutput());
        self::assertTrue($reader->isSuccessful(), $reader->getErrorOutput().$reader->getOutput());
        /** @var array{reads: int, torn: int} $seen */
        $seen = json_decode($reader->getOutput(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertGreaterThan(10, $seen['reads']);
        self::assertSame(0, $seen['torn'], 'a read saw a header and lines of different saves');
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
            $file = sys_get_temp_dir().'/foresi-race-'.bin2hex(random_bytes(4)).'.db';
            $this->sqliteFiles[] = $file;
            $url = 'sqlite:///'.$file;
        }
        $seed = $this->process($url, ['seed', (string) $invoices, (string) $lines]);
        $seed->mustRun();

        return [$url, $seed->getOutput()];
    }

    /**
     * Seeds the invoices and races the jobs on them, and proves the processes met: in
     * most time slots all of them were at work on that invoice at once. A busy machine
     * can keep them apart, and then the race proved nothing, so it runs again on fresh
     * invoices; only processes that never meet fail the test.
     *
     * @param list<string> $jobs
     *
     * @return array{string, string, list<list<string>>} the database URL, the ids as JSON and the results per job, one per time slot
     */
    private function raced(string $kind, int $invoices, int $lines, array $jobs): array
    {
        $needed = (int) ceil($invoices / 2);
        for ($attempt = 1;; ++$attempt) {
            [$url, $ids] = $this->seeded($kind, $invoices, $lines);
            [$results, $met] = $this->race($url, $ids, $jobs);
            if ($met >= $needed || self::ATTEMPTS === $attempt) {
                self::assertGreaterThanOrEqual($needed, $met, 'the processes did not meet on the invoices in '.self::ATTEMPTS.' attempts');

                return [$url, $ids, $results];
            }
        }
    }

    /**
     * Starts one process per job and counts the time slots in which all of them were at
     * work on that invoice at once.
     *
     * @param list<string> $jobs
     *
     * @return array{list<list<string>>, int} the results per job, one per time slot, and the slots they met in
     */
    private function race(string $url, string $ids, array $jobs): array
    {
        $startAt = \sprintf('%.6F', microtime(true) + self::START_DELAY);
        $processes = array_map(fn (string $job): Process => $this->process($url, ['run', $ids, $job, $startAt]), $jobs);
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        $began = [];
        $ended = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            /** @var array{results: list<string>, began: list<float>, ended: list<float>} $outcome */
            $outcome = json_decode($process->getOutput(), true, 8, \JSON_THROW_ON_ERROR);
            $results[] = $outcome['results'];
            $began[] = $outcome['began'];
            $ended[] = $outcome['ended'];
        }
        $met = 0;
        foreach (array_keys($results[0]) as $slot) {
            $lastBegan = 0.0;
            $firstEnded = \PHP_FLOAT_MAX;
            foreach (array_keys($results) as $process) {
                $lastBegan = max($lastBegan, $began[$process][$slot]);
                $firstEnded = min($firstEnded, $ended[$process][$slot]);
            }
            $met += $lastBegan < $firstEnded ? 1 : 0;
        }

        return [$results, $met];
    }

    /**
     * One save went through, the other was told the form is stale, and the document
     * holds exactly the winner.
     *
     * @param Stored $invoice
     */
    private function assertOneWon(string $first, string $second, array $invoice): void
    {
        $outcome = [$first, $second];
        sort($outcome);
        self::assertSame(['done', 'refused:invoice.problem.changed_meanwhile'], $outcome);
        self::assertSame(2, $invoice['version'], 'one save, one version');
        self::assertSame($invoice['lineNet'], $invoice['headerNet'], 'the header and the lines agree');
        self::assertSame(range(0, \count($invoice['positions']) - 1), $invoice['positions']);
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
