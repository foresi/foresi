<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Console;

use Foresi\Shared\Infrastructure\Module\DeptracModulesConfig;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes deptrac.modules.yaml from the module manifests. With --check it only
 * compares, so CI fails when a manifest changed and the file was not
 * regenerated.
 */
#[AsCommand(
    name: 'app:modules:deptrac',
    description: 'Generate the module-level Deptrac configuration from the module manifests',
    help: <<<'HELP'
    Writes deptrac.modules.yaml, the module-level Deptrac configuration, from the module manifests.
    Run it when a module or a dependency between modules changed, then commit the file. With --check it
    writes nothing and compares only; CI runs it (composer modules:check) and exits with 1 when the file
    differs from the manifests. Writing is safe to repeat. It exits with 1 when the file cannot be
    written.
    HELP,
)]
final readonly class ModulesDeptracCommand
{
    public function __construct(
        private InstalledModules $modules,
        private DeptracModulesConfig $config,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only compare the file with the manifests; fail when it differs')]
        bool $check = false,
        #[Option(description: 'The file to write or compare; deptrac.modules.yaml of the project when left out')]
        ?string $file = null,
    ): int {
        $file ??= $this->projectDir.'/deptrac.modules.yaml';
        $expected = $this->config->render($this->modules->graph());

        if ($check) {
            if (is_file($file) && file_get_contents($file) === $expected) {
                $io->success(\sprintf('%s matches the module manifests.', basename($file)));

                return Command::SUCCESS;
            }
            $io->error(\sprintf('%s does not match the module manifests. Run bin/console app:modules:deptrac and commit the file.', basename($file)));

            return Command::FAILURE;
        }

        if (false === file_put_contents($file, $expected)) {
            $io->error(\sprintf('Cannot write %s.', $file));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Wrote %s.', basename($file)));

        return Command::SUCCESS;
    }
}
