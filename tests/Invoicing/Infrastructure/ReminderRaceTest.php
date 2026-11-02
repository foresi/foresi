<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Tests\Invoicing\Infrastructure\Support\ReminderWorker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Two reminder runs of one tenant at once, in real processes, on the database
 * the suite runs on and always on a file SQLite database, as SendRaceTest does:
 * a second worker, a catch-up after a given-back period or a run started by hand
 * must never remind an invoice of one step twice.
 */
#[Group('race')]
final class ReminderRaceTest extends KernelTestCase
{
    private const string WORKER = 'tests/Invoicing/Infrastructure/Support/reminder-race.php';
    private const int ROUNDS = 6;
    private const float START_DELAY = 4.0;

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
    public function testTwoRunsSendStepOnce(string $kind): void
    {
        $url = self::suiteUrl();
        if ('sqlite' === $kind) {
            $this->sqliteFile = sys_get_temp_dir().'/foresi-reminder-race-'.bin2hex(random_bytes(4)).'.db';
            $url = 'sqlite:///'.$this->sqliteFile;
        }
        $seed = $this->process($url, ['seed', (string) self::ROUNDS]);
        $seed->mustRun();
        $ids = $seed->getOutput();

        $this->race($url, $ids, ['remind', 'remind']);

        $inspect = $this->process($url, ['inspect', $ids]);
        $inspect->mustRun();
        /** @var list<list<list<int>>> $stored */
        $stored = json_decode($inspect->getOutput(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertCount(self::ROUNDS, $stored);
        foreach ($stored as $tenant) {
            self::assertSame(array_fill(0, ReminderWorker::INVOICES, [1]), $tenant, 'one first reminder per invoice, whichever run took it');
        }
    }

    private static function suiteUrl(): string
    {
        $url = $_SERVER['DATABASE_URL'] ?? '';

        return \is_string($url) ? $url : '';
    }

    /**
     * Starts both runs and proves they met in most slots.
     *
     * @param list<string> $jobs
     */
    private function race(string $url, string $ids, array $jobs): void
    {
        $startAt = \sprintf('%.6F', microtime(true) + self::START_DELAY);
        $processes = array_map(fn (string $job): Process => $this->process($url, ['run', $ids, $job, $startAt]), $jobs);
        foreach ($processes as $process) {
            $process->start();
        }
        $began = [];
        $ended = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            /** @var array{results: list<string>, began: list<float>, ended: list<float>} $outcome */
            $outcome = json_decode($process->getOutput(), true, 8, \JSON_THROW_ON_ERROR);
            $began[] = $outcome['began'];
            $ended[] = $outcome['ended'];
        }
        $met = 0;
        foreach (array_keys($began[0]) as $slot) {
            $met += max($began[0][$slot], $began[1][$slot]) < min($ended[0][$slot], $ended[1][$slot]) ? 1 : 0;
        }
        self::assertGreaterThanOrEqual((int) ceil(self::ROUNDS / 2), $met, 'the runs did not meet on the tenants');
    }

    /**
     * @param list<string> $arguments
     */
    private function process(string $url, array $arguments): Process
    {
        $root = self::getContainer()->getParameter('kernel.project_dir');

        return new Process([\PHP_BINARY, self::WORKER, ...$arguments], $root, ['DATABASE_URL' => $url, 'APP_ENV' => 'test'], null, 180);
    }
}
