<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The real commands of the application: a migration that works in PHP cannot
 * be rehearsed, whichever option asks for it and whichever command takes it.
 */
final class MigrationRehearsalTest extends KernelTestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/foresi-rehearsal-'.bin2hex(random_bytes(4)).'.sql';
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->connection()->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('rehearsals')]
    public function testARehearsalIsRefusedBeforeAnythingRuns(string $command, array $options): void
    {
        $input = ['command' => $command] + $options;
        if ('doctrine:migrations:execute' === $command) {
            $input['versions'] = ['DoctrineMigrations\Version20261101000001'];
            $input['--up'] = true;
        }
        if (isset($input['--write-sql'])) {
            $input['--write-sql'] = $this->file;
        }

        $tester = $this->console($input);

        self::assertNotSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('would not rehearse it', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(0, $this->recordedVersions());
        self::assertFileDoesNotExist($this->file);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function rehearsals(): iterable
    {
        yield 'migrate --dry-run' => ['doctrine:migrations:migrate', ['--dry-run' => true]];
        yield 'migrate --write-sql' => ['doctrine:migrations:migrate', ['--write-sql' => true]];
        yield 'migrate both' => ['doctrine:migrations:migrate', ['--dry-run' => true, '--write-sql' => true]];
        yield 'execute --dry-run' => ['doctrine:migrations:execute', ['--dry-run' => true]];
        yield 'execute --write-sql' => ['doctrine:migrations:execute', ['--write-sql' => true]];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): ApplicationTester
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('The kernel is booted.'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run($input, ['interactive' => false, 'capture_stderr_separately' => false]);

        return $tester;
    }

    private function recordedVersions(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
