<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Health;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The checks of /health from the shell, for cron or a monitoring agent: the
 * exit code is non-zero when the installation is down.
 */
#[AsCommand(name: 'app:health', description: 'Check the database, worker, schedule, disk and system mail')]
final readonly class HealthCommand
{
    public function __construct(
        private HealthCheck $check,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $health = $this->check->run();
        $rows = [];
        foreach ($health->checks as $name => $check) {
            $rows[] = [$name, $check['status']->value, $check['value'] ?? '-'];
        }
        $io->table(['Check', 'Status', 'Value'], $rows);
        $status = $health->status();
        $io->writeln('Status: '.$status->value);

        return HealthStatus::Down === $status ? Command::FAILURE : Command::SUCCESS;
    }
}
