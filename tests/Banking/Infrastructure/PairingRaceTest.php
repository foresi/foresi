<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Pairing in real parallel processes, on the database the suite runs on and always
 * on a file SQLite database, as SendRaceTest does. Each slot has one invoice of
 * 1,210.00 CZK and two verified notices of that amount.
 *
 * @phpstan-type Stored array{paid: int, allocations: int, movements: list<array{state: string, reason: ?string, payments: int, cancelled: int}>}
 */
#[Group('race')]
final class PairingRaceTest extends KernelTestCase
{
    private const string WORKER = 'tests/Banking/Infrastructure/Support/pairing-race.php';
    private const int ROUNDS = 6;
    private const int PAYABLE = 121000;
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
    public function testTwoNoticesForOneInvoicePayItOnce(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind);

        [$first, $second] = $this->race($url, $ids, ['auto-first', 'auto-second']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            $outcome = [$first[$round], $second[$round]];
            sort($outcome);
            self::assertSame(['left', 'paired'], $outcome, 'one notice paid it, the other found nothing left to pay');
            self::assertSame(self::PAYABLE, $invoice['paid']);
            self::assertSame(1, $invoice['allocations']);
            $states = array_map(static fn (array $movement): array => [$movement['state'], $movement['reason'], $movement['payments']], $invoice['movements']);
            sort($states);
            self::assertSame([['paired', null, 1], ['unmatched', 'no_invoice', 0]], $states);
        }
    }

    #[DataProvider('databases')]
    public function testAutoAndManualPairingOfOneMovementMakeOnePayment(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind);

        [$auto, $manual] = $this->race($url, $ids, ['auto-first', 'manual-first']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertContains([$auto[$round], $manual[$round]], [['paired', 'refused:already_paired'], ['left', 'paired']]);
            self::assertSame(self::PAYABLE, $invoice['paid']);
            self::assertSame(1, $invoice['allocations']);
            self::assertSame(['paired', null, 1], [$invoice['movements'][0]['state'], $invoice['movements'][0]['reason'], $invoice['movements'][0]['payments']]);
            self::assertSame(0, $invoice['movements'][1]['payments']);
        }
    }

    /**
     * Unpairing on the bank tab and removing the payment on the invoice take the
     * movement and the invoice in one order, so neither waits on the other in a
     * deadlock that a retry would only hide.
     */
    #[DataProvider('databases')]
    public function testUnpairingAndCancellingOnePaymentCancelItOnceWithoutADeadlock(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, paired: true);
        $deadlocks = $this->deadlocks($url);

        [$unpair, $cancel] = $this->race($url, $ids, ['unpair-first', 'cancel-first']);

        self::assertSame($deadlocks, $this->deadlocks($url), 'PostgreSQL found no deadlock');
        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertContains($unpair[$round], ['unpaired', 'refused:not_paired']);
            self::assertSame('cancelled', $cancel[$round]);
            self::assertSame(0, $invoice['paid']);
            self::assertSame(0, $invoice['allocations']);
            $first = $invoice['movements'][0];
            self::assertSame(['unmatched', 'blocked', 0, 1], [$first['state'], $first['reason'], $first['payments'], $first['cancelled']], 'one cancelled payment and the movement blocked');
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
    private function seeded(string $kind, bool $paired = false): array
    {
        $url = self::suiteUrl();
        if ('sqlite' === $kind) {
            $this->sqliteFile = sys_get_temp_dir().'/foresi-pairing-race-'.bin2hex(random_bytes(4)).'.db';
            $url = 'sqlite:///'.$this->sqliteFile;
        }
        $seed = $this->process($url, ['seed', (string) self::ROUNDS, ...($paired ? ['paired'] : [])]);
        $seed->mustRun();

        return [$url, $seed->getOutput()];
    }

    private function deadlocks(string $url): ?int
    {
        $count = $this->process($url, ['deadlocks']);
        $count->mustRun();
        $deadlocks = json_decode($count->getOutput(), true, 8, \JSON_THROW_ON_ERROR);
        \assert(null === $deadlocks || \is_int($deadlocks));

        return $deadlocks;
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
            $met += max($began[0][$slot], $began[1][$slot]) < min($ended[0][$slot], $ended[1][$slot]) && abs($began[0][$slot] - $began[1][$slot]) < 0.2 ? 1 : 0;
        }
        self::assertGreaterThanOrEqual((int) ceil(\count($results[0]) / 2), $met, 'the processes did not meet on the invoices');

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
