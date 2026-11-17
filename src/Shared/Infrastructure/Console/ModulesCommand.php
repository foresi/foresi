<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Domain\Module\ModuleGraph;
use App\Shared\Domain\Module\ModuleId;
use App\Shared\Infrastructure\Module\InstalledModules;
use App\Shared\Infrastructure\Module\ModuleIntegration;
use App\Shared\Infrastructure\Module\ModuleIntegrations;
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
#[AsCommand(name: 'app:modules', description: 'List the installed modules, their dependencies, their integrations and the problems of the module graph')]
final readonly class ModulesCommand
{
    public function __construct(
        private InstalledModules $modules,
        private ModuleIntegrations $integrations,
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

        $graph = $this->modules->graph();

        return $this->show($io, $graph, $this->modules->acceptedProblems(), $format, $this->integrations->of($graph));
    }

    /**
     * @param list<string>            $accepted
     * @param list<ModuleIntegration> $integrations
     */
    public function show(SymfonyStyle $io, ModuleGraph $graph, array $accepted, string $format, array $integrations = []): int
    {
        $unaccepted = ModulesView::unaccepted($graph, $accepted);
        if ('json' === $format) {
            $io->writeln(json_encode(ModulesView::toArray($graph, $integrations), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return [] === $unaccepted ? Command::SUCCESS : Command::FAILURE;
        }

        $view = ModulesView::toArray($graph, $integrations);
        $io->table(
            ['Module', 'Kind', 'Package', 'Requires', 'Optional', 'Scopes'],
            array_map(static fn (array $module): array => [
                $module['id'],
                $module['kind'],
                $module['package'],
                self::listed($module['requires']),
                self::listed(array_map(
                    static fn (string $id): string => $id.($graph->has(ModuleId::of($id)) ? ' (installed)' : ' (absent)'),
                    $module['optional'],
                )),
                self::listed($module['scopes']),
            ], $view['modules']),
        );
        if ([] === $view['integrations']) {
            $io->writeln('No integrations.');
        } else {
            $io->writeln('Integrations:');
            $io->listing(array_map(
                static fn (array $integration): string => \sprintf(
                    '%s uses %s through %s (%s)',
                    $integration['module'],
                    $integration['uses'],
                    $integration['namespace'],
                    $integration['loaded'] ? 'loaded' : 'not loaded, '.$integration['uses'].' is absent',
                ),
                $view['integrations'],
            ));
        }
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
