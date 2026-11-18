<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Module;

use Foresi\Shared\Domain\Module\InvalidManifest;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The integration code of the installed modules (ADR 0033): every directory
 * src/<Module>/Integration/<Other>/, where <Other> is a module the manifest
 * lists in extra.foresi.optional. The module's own services leave these
 * directories out; the kernel registers one only when <Other> is installed,
 * so a module runs unchanged without it and its integration code never meets
 * a missing class.
 */
final readonly class ModuleIntegrations
{
    public const string DIRECTORY = 'Integration';

    /**
     * @param string $srcDir        the directory the root namespace maps to
     * @param string $rootNamespace the namespace of $srcDir; module namespaces start with "Foresi\"
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/src')]
        private string $srcDir,
        private string $rootNamespace = 'Foresi\\',
    ) {
    }

    /**
     * @return list<ModuleIntegration> by module, then by the module it uses
     *
     * @throws InvalidManifest for an integration directory of a module the manifest does not list as optional
     */
    public function of(ModuleGraph $graph): array
    {
        $integrations = [];
        foreach ($graph->all() as $module) {
            $relative = $this->relative($module);
            $base = $this->srcDir.'/'.$relative.'/'.self::DIRECTORY;
            foreach ($this->subdirectories($base) as $name) {
                $uses = $this->optionalIn($module, $name) ?? throw new InvalidManifest(\sprintf('Module %s has integration code in src/%s/%s/%s but lists no module %s in extra.foresi.optional of its composer.json.', $module->id->value, $relative, self::DIRECTORY, $name, $name));
                $integrations[] = new ModuleIntegration(
                    $module->id,
                    $uses,
                    $this->rootNamespace.$relative.'\\'.self::DIRECTORY.'\\'.$name.'\\',
                    $base.'/'.$name,
                    $graph->has($uses),
                );
            }
        }
        usort($integrations, static fn (ModuleIntegration $a, ModuleIntegration $b): int => [$a->module->value, $a->uses->value] <=> [$b->module->value, $b->uses->value]);

        return $integrations;
    }

    /**
     * Registers the loaded integrations as services, autowired and
     * autoconfigured like the rest of src/.
     *
     * @return list<ModuleIntegration> all integrations, loaded or not
     */
    public function register(ModuleGraph $graph, ContainerConfigurator $container): array
    {
        $integrations = $this->of($graph);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        foreach ($integrations as $integration) {
            if ($integration->loaded) {
                $services->load($integration->namespace, $integration->directory.'/');
            }
        }

        return $integrations;
    }

    private function relative(ModuleManifest $module): string
    {
        // The manifest guarantees "Foresi\<Name>\".
        return substr($module->namespace, \strlen('Foresi\\'), -1);
    }

    /**
     * The optional dependency whose directory is $name, such as cash-flow for CashFlow.
     */
    private function optionalIn(ModuleManifest $module, string $name): ?ModuleId
    {
        foreach ($module->optional as $optional) {
            if ($optional->directory() === $name) {
                return $optional;
            }
        }

        return null;
    }

    /**
     * @return list<string> sorted
     */
    private function subdirectories(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $names = [];
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDir() && !$entry->isDot()) {
                $names[] = $entry->getFilename();
            }
        }
        sort($names, \SORT_STRING);

        return $names;
    }
}
