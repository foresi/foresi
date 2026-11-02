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
 * Sending, delivery and trash in real parallel processes, on the database the
 * suite runs on and always on a file SQLite database, as InvoiceRaceTest does.
 * The mail server takes 0.4 s in each process, so a second worker arrives while
 * the first one still talks to it.
 *
 * @phpstan-type Stored array{trashed: bool, delivered: bool, sends: list<array{status: string, revoked: bool}>}
 */
#[Group('race')]
final class SendRaceTest extends KernelTestCase
{
    private const string WORKER = 'tests/Invoicing/Infrastructure/Support/send-race.php';
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
    public function testTwoWorkersOnOneSendMailTheCustomerOnce(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, true);

        [$first, $second] = $this->race($url, $ids, ['deliver', 'deliver']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            $outcome = [$first[$round], $second[$round]];
            sort($outcome);
            self::assertSame(['mailed', 'waiting'], $outcome, 'one worker talked to the server, the other waits for its lease to end');
            self::assertSame([['status' => 'sent', 'revoked' => false]], $invoice['sends']);
            self::assertTrue($invoice['delivered']);
        }
    }

    #[DataProvider('databases')]
    public function testADeliveryAndATrashNeverLeaveATrashedInvoiceDelivered(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, true);

        [$deliver, $trash] = $this->race($url, $ids, ['deliver', 'trash']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertFalse($invoice['trashed'] && $invoice['delivered'], 'never both');
            [$send] = $invoice['sends'];
            if ('done' === $trash[$round]) {
                self::assertTrue($invoice['trashed']);
                self::assertTrue($send['revoked'], 'trash ended the link');
                // Mailed before or while the trash happened: what went out is recorded as sent.
                self::assertSame('mailed' === $deliver[$round] ? 'sent' : 'cancelled', $send['status']);
            } else {
                self::assertSame('refused', $trash[$round]);
                self::assertSame(['mailed', 'sent', true, false], [$deliver[$round], $send['status'], $invoice['delivered'], $send['revoked']]);
            }
        }
    }

    #[DataProvider('databases')]
    public function testASendNeverWaitsOnATrashedInvoice(string $kind): void
    {
        [$url, $ids] = $this->seeded($kind, false);

        [$send, $trash] = $this->race($url, $ids, ['send', 'trash']);

        foreach ($this->stored($url, $ids) as $round => $invoice) {
            self::assertSame('done', $trash[$round]);
            self::assertTrue($invoice['trashed']);
            if ('done' === $send[$round]) {
                self::assertSame([['status' => 'cancelled', 'revoked' => true]], $invoice['sends'], 'the trash came after and stopped it');
            } else {
                self::assertSame('refused:trashed', $send[$round]);
                self::assertSame([], $invoice['sends']);
            }
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
    private function seeded(string $kind, bool $queued): array
    {
        $url = self::suiteUrl();
        if ('sqlite' === $kind) {
            $this->sqliteFile = sys_get_temp_dir().'/foresi-send-race-'.bin2hex(random_bytes(4)).'.db';
            $url = 'sqlite:///'.$this->sqliteFile;
        }
        $seed = $this->process($url, ['seed', (string) self::ROUNDS, $queued ? '1' : '0']);
        $seed->mustRun();

        return [$url, $seed->getOutput()];
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
