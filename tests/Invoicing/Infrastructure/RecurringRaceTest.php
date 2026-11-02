<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Domain\PeriodText;
use App\Tests\Invoicing\Infrastructure\Support\RecurringWorker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Issuing an occurrence while another process issues it too, pauses the template
 * or edits it: real parallel processes on the database the suite runs on and
 * always on a file SQLite database, as SendRaceTest does. Each round is one
 * template whose next occurrence is today.
 *
 * @phpstan-type Stored array{state: string, next: string|null, issued: int, invoices: list<list<string>>}
 */
#[Group('race')]
final class RecurringRaceTest extends KernelTestCase
{
    private const string WORKER = 'tests/Invoicing/Infrastructure/Support/recurring-race.php';
    private const int ROUNDS = 6;
    private const float START_DELAY = 3.0;

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
    public function testTwoWorkersOnOneOccurrenceIssueOnce(string $kind): void
    {
        [$url, $ids, $today] = $this->seeded($kind);

        [$first, $second] = $this->race($url, $ids, ['issue', 'issue']);

        foreach ($this->stored($url, $ids) as $round => $template) {
            $outcome = [$first[$round], $second[$round]];
            sort($outcome);
            self::assertSame(['issued', 'taken'], $outcome, 'one worker issued, the other found it taken');
            self::assertCount(1, $template['invoices']);
            self::assertSame(['active', $this->nextMonth($today), 1], [$template['state'], $template['next'], $template['issued']], 'the template moved on once');
        }
    }

    #[DataProvider('databases')]
    public function testIssueAndPauseNeverIssueAfterThePause(string $kind): void
    {
        [$url, $ids, $today] = $this->seeded($kind);

        [$issue, $pause] = $this->race($url, $ids, ['issue', 'pause']);

        foreach ($this->stored($url, $ids) as $round => $template) {
            self::assertSame('paused', $pause[$round]);
            self::assertSame('paused', $template['state']);
            if ('issued' === $issue[$round]) {
                // Issued first, then paused.
                self::assertSame([1, 1, $this->nextMonth($today)], [\count($template['invoices']), $template['issued'], $template['next']]);
            } else {
                // Paused first: nothing was issued, and the occurrence still waits.
                self::assertSame('taken', $issue[$round]);
                self::assertSame([0, 0, $today], [\count($template['invoices']), $template['issued'], $template['next']]);
            }
        }
    }

    #[DataProvider('databases')]
    public function testIssueAndEditGiveOneWholeVersion(string $kind): void
    {
        [$url, $ids, $today] = $this->seeded($kind);

        [$issue, $edit] = $this->race($url, $ids, ['issue', 'edit']);

        foreach ($this->stored($url, $ids) as $round => $template) {
            self::assertSame('issued', $issue[$round], 'an edit keeps the date, so the occurrence is issued either way');
            self::assertCount(1, $template['invoices']);
            if ('edited' === $edit[$round]) {
                // Saved before the run locked the template: the invoice is the new version.
                self::assertSame($this->resolved(RecurringWorker::NEW_LINES, $today), $template['invoices'][0]);
            } else {
                // The run issued first and moved the version on, so the form was out of date.
                self::assertSame('refused:invoice.problem.changed_meanwhile', $edit[$round]);
                self::assertSame($this->resolved(RecurringWorker::OLD_LINES, $today), $template['invoices'][0]);
            }
            self::assertSame([1, $this->nextMonth($today)], [$template['issued'], $template['next']]);
        }
    }

    private static function suiteUrl(): string
    {
        $url = $_SERVER['DATABASE_URL'] ?? '';

        return \is_string($url) ? $url : '';
    }

    /**
     * @param list<string> $texts
     *
     * @return list<string>
     */
    private function resolved(array $texts, string $occurrence): array
    {
        return array_map(static fn (string $text): string => PeriodText::resolve($text, new \DateTimeImmutable($occurrence)), $texts);
    }

    private function nextMonth(string $today): string
    {
        $day = new \DateTimeImmutable($today);
        $first = $day->modify('first day of next month');
        $length = (int) $first->format('t');

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $day->format('j'), $length))->format('Y-m-d');
    }

    /**
     * @return array{string, string, string} the database URL, the ids as JSON and the day the templates are due
     */
    private function seeded(string $kind): array
    {
        $url = self::suiteUrl();
        if ('sqlite' === $kind) {
            $this->sqliteFile = sys_get_temp_dir().'/foresi-recurring-race-'.bin2hex(random_bytes(4)).'.db';
            $url = 'sqlite:///'.$this->sqliteFile;
        }
        $seed = $this->process($url, ['seed', (string) self::ROUNDS]);
        $seed->mustRun();
        /** @var array{today: string} $ids */
        $ids = json_decode($seed->getOutput(), true, 8, \JSON_THROW_ON_ERROR);

        return [$url, $seed->getOutput(), $ids['today']];
    }

    /**
     * Starts one process per job and proves they met: in most slots each began
     * before the other ended. Scheduling may spoil a slot, so not in all.
     *
     * @param list<string> $jobs
     *
     * @return list<list<string>> the results per job, one per time slot
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
            $met += max($began[0][$slot], $began[1][$slot]) < max($ended[0][$slot], $ended[1][$slot]) && abs($began[0][$slot] - $began[1][$slot]) < 0.2 ? 1 : 0;
        }
        self::assertGreaterThanOrEqual((int) ceil(\count($results[0]) / 2), $met, 'the processes did not meet on the templates');

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
