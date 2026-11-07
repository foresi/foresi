<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\TestAlertCommand;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The operator checks the error mail path: the command logs exactly one error.
 */
final class TestAlertCommandTest extends KernelTestCase
{
    public function testItLogsOneError(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };

        $status = (new TestAlertCommand($logger))(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertStringContainsString('app:ops:test-alert', $logger->records[0][1]);
    }

    public function testItIsRegistered(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:ops:test-alert'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('error was logged', $tester->getDisplay());
    }
}
