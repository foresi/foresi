<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Domain\Module\ModuleGraph;
use App\Shared\Domain\Module\ModuleId;
use App\Shared\Infrastructure\Module\InstalledModules;
use App\Shared\Infrastructure\Module\ModulesView;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The installed modules and the problems of their graph. The exit code is
 * non-zero when a problem is not in extra.foresi.accepted-problems of the root
 * composer.json.
 */
#[AsCommand(name: 'app:modules', description: 'List the installed modules, their dependencies and the problems of the module graph')]
final readonly class ModulesCommand
{
    public function __construct(
        private InstalledModules $modules,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'table or json')]
        string $format = 'table',
    ): int {
        if (!\in_array($format, ['table', 'json'], true)) {
            $io->error('The format must be table or json.');

            return Command::INVALID;
        }

        return $this->show($io, $this->modules->graph(), $this->modules->acceptedProblems(), $format);
    }

    /**
     * @param list<string> $accepted
     */
    public function show(SymfonyStyle $io, ModuleGraph $graph, array $accepted, string $format): int
    {
        $unaccepted = ModulesView::unaccepted($graph, $accepted);
        if ('json' === $format) {
            $io->writeln(json_encode(ModulesView::toArray($graph), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return [] === $unaccepted ? Command::SUCCESS : Command::FAILURE;
        }

        $view = ModulesView::toArray($graph);
        $io->table(
            ['Module', 'Kind', 'Package', 'Requires', 'Optional'],
            array_map(static fn (array $module): array => [
                $module['id'],
                $module['kind'],
                $module['package'],
                self::listed($module['requires']),
                self::listed(array_map(
                    static fn (string $id): string => $id.($graph->has(ModuleId::of($id)) ? ' (installed)' : ' (absent)'),
                    $module['optional'],
                )),
            ], $view['modules']),
        );
        if ([] === $view['problems']) {
            $io->writeln('No problems.');
        } else {
            $io->writeln('Problems:');
            $io->listing(array_map(
                static fn (string $code): string => $code.(\in_array($code, $unaccepted, true) ? '' : ' (accepted)'),
                $view['problems'],
            ));
        }
        if ([] !== $unaccepted) {
            $io->error('Problems not accepted in extra.foresi.accepted-problems of composer.json: '.implode(', ', $unaccepted));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $items
     */
    private static function listed(array $items): string
    {
        return [] === $items ? '-' : implode(', ', $items);
    }
}
