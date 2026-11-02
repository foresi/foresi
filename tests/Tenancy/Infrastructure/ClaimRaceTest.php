<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Real worker processes claiming the same period at the same moment, on a file
 * SQLite database and on the suite's PostgreSQL when it runs on one. Opt-in:
 * vendor/bin/phpunit --group claim-race.
 */
#[Group('claim-race')]
final class ClaimRaceTest extends KernelTestCase
{
    private const string WORKER = 'tests/Tenancy/Infrastructure/Support/claim-race.php';
    private const int WORKERS = 4;
    private const int ROUNDS = 20;

    private ?string $sqliteFile = null;

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
    public function testOnlyOneWorkerClaimsEachPeriod(string $kind): void
    {
        self::bootKernel();
        $url = self::suiteUrl();
        if ('sqlite' === $kind) {
            $this->sqliteFile = sys_get_temp_dir().'/foresi-claim-race-'.bin2hex(random_bytes(4)).'.db';
            $url = 'sqlite:///'.$this->sqliteFile;
        }
        $seed = $this->process($url, ['seed']);
        $seed->mustRun();
        $tenant = $seed->getOutput();

        $startAt = \sprintf('%.6F', microtime(true) + 3.0);
        $workers = [];
        for ($i = 0; $i < self::WORKERS; ++$i) {
            $workers[] = $this->process($url, ['claim', $tenant, $startAt, (string) self::ROUNDS]);
        }
        foreach ($workers as $worker) {
            $worker->start();
        }
        $claims = array_fill(0, self::ROUNDS, 0);
        foreach ($workers as $worker) {
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            $results = json_decode($worker->getOutput(), true, 4, \JSON_THROW_ON_ERROR);
            self::assertIsArray($results);
            foreach ($results as $round => $claimed) {
                self::assertIsInt($round);
                $claims[$round] += 1 === $claimed ? 1 : 0;
            }
        }

        self::assertSame(array_fill(0, self::ROUNDS, 1), $claims, 'Each period was claimed by exactly one worker.');
    }

    private static function suiteUrl(): string
    {
        $url = $_SERVER['DATABASE_URL'] ?? '';

        return \is_string($url) ? $url : '';
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
