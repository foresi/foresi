<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Console;

use Foresi\Shared\Infrastructure\Migration\MigrationRebase;
use Foresi\Shared\Infrastructure\Migration\RebaseState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves a database from the global migration history onto the module
 * baselines (ADR 0034), changing the rows of the executed versions only.
 * Take a backup first (ops/backup.sh --tag pre-rebase).
 *
 * With --check it changes nothing and tells the state by its exit code:
 * 0 when there is nothing to rebase (a new database or one already on the
 * baselines), 2 when the database is on the global history, 1 otherwise.
 * ops/deploy.sh uses it to leave such a database alone.
 */
#[AsCommand(name: 'app:migrations:rebase', description: 'Move a database from the global migration history onto the module baselines, without DDL')]
final readonly class RebaseMigrationsCommand
{
    public const int PENDING = 2;

    public function __construct(private MigrationRebase $rebase)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only tell the state: exit 0 with nothing to rebase, 2 when a rebase is due, 1 otherwise')]
        bool $check = false,
    ): int {
        $state = $this->rebase->state();
        if ($check) {
            $io->writeln(\sprintf('Migration history: %s.', $state->value));

            return match ($state) {
                RebaseState::Fresh, RebaseState::Rebased => Command::SUCCESS,
                RebaseState::Global => self::PENDING,
                RebaseState::Mixed => Command::FAILURE,
            };
        }
        if (RebaseState::Rebased === $state) {
            $io->success('The database is already on the module baselines; nothing to do.');

            return Command::SUCCESS;
        }
        $refusals = $this->rebase->refusals();
        if ([] !== $refusals) {
            $io->error(['The database was not changed.', ...$refusals]);

            return Command::FAILURE;
        }
        $result = $this->rebase->rebase();
        $io->listing($result['added']);
        $io->success(\sprintf('Replaced the %d versions of the global history with the %d baselines above. No schema was changed.', $result['removed'], \count($result['added'])));

        return Command::SUCCESS;
    }
}
