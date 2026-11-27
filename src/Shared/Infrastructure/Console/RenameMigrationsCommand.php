<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Console;

use Foresi\Shared\Infrastructure\Migration\MigrationHistoryRename;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gives the executed versions of a module that changed its name the new
 * namespace of its migrations, changing the rows of the history only (the
 * map is MigrationHistoryRename::RENAMES). Run it before
 * doctrine:migrations:migrate; ops/deploy.sh does. Take a backup first
 * (ops/backup.sh --tag pre-rename).
 *
 * With --check it changes nothing and tells the state by its exit code:
 * 0 when nothing is left to rename, 2 when a rename is due, 1 otherwise
 * (the old and the new row of one version both exist).
 */
#[AsCommand(name: 'app:migrations:rename', description: 'Rename the executed migration versions of the modules that changed their name, without DDL')]
final readonly class RenameMigrationsCommand
{
    public const int PENDING = 2;

    public function __construct(private MigrationHistoryRename $rename)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only tell the state: exit 0 with nothing to rename, 2 when a rename is due, 1 otherwise')]
        bool $check = false,
    ): int {
        $plan = $this->rename->plan();
        if ([] !== $plan['conflicts']) {
            $io->error(['The database was not changed.', ...array_map(static fn (string $version): string => \sprintf('The versions %s and %s both exist.', $version, MigrationHistoryRename::renamed($version)), $plan['conflicts'])]);

            return Command::FAILURE;
        }
        if ($check) {
            $io->writeln([] === $plan['due'] ? 'Migration history: nothing to rename.' : \sprintf('Migration history: %d versions to rename.', \count($plan['due'])));

            return [] === $plan['due'] ? Command::SUCCESS : self::PENDING;
        }
        if ([] === $plan['due']) {
            $io->success('No executed migration carries an old module name; nothing to do.');

            return Command::SUCCESS;
        }
        $renamed = $this->rename->rename();
        $io->listing(array_map(static fn (string $old, string $new): string => \sprintf('%s => %s', $old, $new), array_keys($renamed), $renamed));
        $io->success(\sprintf('Renamed %d executed versions. No schema was changed.', \count($renamed)));

        return Command::SUCCESS;
    }
}
