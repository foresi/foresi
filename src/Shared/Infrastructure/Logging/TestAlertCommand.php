<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Logs one error, so the operator can check that it reaches the log file and
 * their mailbox. The time makes each run a distinct message, which the hourly
 * deduplication of error mails lets through.
 */
#[AsCommand(name: 'app:ops:test-alert', description: 'Log one error to check the error mail to the operator')]
final readonly class TestAlertCommand
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->logger->error('Test alert from app:ops:test-alert at {time}.', ['time' => date(\DATE_ATOM)]);
        $io->success('An error was logged. It is mailed to OPERATOR_EMAIL when OPERATOR_MAILER_DSN is set.');

        return Command::SUCCESS;
    }
}
