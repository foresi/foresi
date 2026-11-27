<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Console;

use Foresi\Shared\Contract\ModuleApi;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Shared\Infrastructure\Module\ModuleApiCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Whether the installed modules are written for the module API this Foresi
 * provides, or, with --api, for the one an upgrade would bring (ADR 0044). The
 * exit code is non-zero when one is not. The deployment runs it after
 * composer install and before the migrations.
 */
#[AsCommand(
    name: 'app:modules:check',
    description: 'Check that the installed modules are written for the module API of this Foresi, or of the version given',
    help: <<<'HELP'
    Checks that every installed module is written for the module API this Foresi provides, or for the
    version given with --api. Run it in CI for a module, and before an upgrade with the version the
    upgrade brings. The deploy runs it after composer install and before the migrations, so an
    incompatible module never touches the database. It is read-only and safe to repeat. It exits with 1
    and names each module that does not fit, and with 2 when --api is not a version.

      bin/console app:modules:check --api=2.0
    HELP,
)]
final readonly class ModulesCheckCommand
{
    public function __construct(private InstalledModules $modules)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The module API version to check against, such as 2.0; the one this Foresi provides when left out')]
        string $api = ModuleApi::VERSION,
    ): int {
        $graph = $this->modules->graph();
        try {
            $problems = ModuleApiCheck::problems($graph, $api);
        } catch (\UnexpectedValueException) {
            $io->error(\sprintf('"%s" is not a version such as 2.0.', $api));

            return Command::INVALID;
        }
        if ([] !== $problems) {
            $io->error(\sprintf('These modules are not written for the module API %s:', $api));
            $io->listing($problems);

            return Command::FAILURE;
        }
        $io->success(\sprintf('All %d installed modules are written for the module API %s.', \count($graph->all()), $api));

        return Command::SUCCESS;
    }
}
