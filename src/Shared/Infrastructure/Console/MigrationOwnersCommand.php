<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Console;

use Foresi\Shared\Infrastructure\Migration\TableOwners;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks that every table of the database belongs to exactly one installed
 * module, the one whose migrations create it (ADR 0034). Run after the
 * migrations: on the full application it finds a table with no owner, on a
 * subset of modules a table of a module that is not installed.
 */
#[AsCommand(name: 'app:migrations:owners', description: 'Check that every table of the database is created by the migrations of one installed module')]
final readonly class MigrationOwnersCommand
{
    public function __construct(private TableOwners $owners)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Also list each table with its module')]
        bool $list = false,
    ): int {
        if ($list) {
            $owners = $this->owners->owners();
            $io->table(['Table', 'Module'], array_map(null, array_keys($owners), array_values($owners)));
        }
        $problems = $this->owners->problems();
        if ([] !== $problems) {
            $io->error($problems);

            return Command::FAILURE;
        }
        $io->success('Every table is created by the migrations of one installed module.');

        return Command::SUCCESS;
    }
}
