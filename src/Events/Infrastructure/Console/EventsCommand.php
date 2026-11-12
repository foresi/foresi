<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Console;

use App\Events\Infrastructure\Catalogue\EventCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The contract events of the installed modules. With --check it prints what is
 * wrong with them and exits non-zero, for the static checks of the build.
 */
#[AsCommand(name: 'app:events', description: 'List the contract events of the installed modules, or check them')]
final readonly class EventsCommand
{
    public function __construct(
        private EventCatalogue $catalogue,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'table or json')]
        string $format = 'table',
        #[Option(description: 'Print the problems and exit 1 when there are any')]
        bool $check = false,
    ): int {
        if (!\in_array($format, ['table', 'json'], true)) {
            $io->error('The format must be table or json.');

            return Command::INVALID;
        }
        $problems = $this->catalogue->problems();
        if ($check) {
            if ([] === $problems) {
                $io->success(\sprintf('%d contract events, no problems.', \count($this->catalogue->all())));

                return Command::SUCCESS;
            }
            $io->error('The contract events have problems:');
            $io->listing($problems);

            return Command::FAILURE;
        }

        $rows = array_map(static fn (\App\Events\Infrastructure\Catalogue\EventType $type): array => [
            'name' => $type->name,
            'version' => $type->version,
            'module' => $type->module->value,
            'class' => $type->class,
            'fields' => $type->fields,
        ], $this->catalogue->all());
        if ('json' === $format) {
            $io->writeln(json_encode(['events' => $rows, 'problems' => $problems], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io->table(
            ['Name', 'Version', 'Module', 'Facts'],
            array_map(static fn (array $row): array => [$row['name'], $row['version'], $row['module'], [] === $row['fields'] ? '-' : implode(', ', $row['fields'])], $rows),
        );
        if ([] !== $problems) {
            $io->writeln('Problems:');
            $io->listing($problems);
        }

        return Command::SUCCESS;
    }
}
