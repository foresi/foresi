<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Module;

use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The bundle of a module: Foresi\<Module>\<Module>Bundle at the module's
 * root, named in extra.foresi.bundle of its composer.json. The kernel
 * registers the bundle of every installed module, so being installed is
 * still the only switch.
 *
 * The bundle loads the module's own services from config/services.php and
 * prepends what the module owns in the configuration of other bundles: the
 * Doctrine mapping of its entities, the Messenger routing of its messages,
 * its Stimulus controllers and whatever {@see prepend()} adds. Templates
 * (@<Module>) and translations are found by Symfony itself. The application's own configuration
 * is merged after the bundles', so it can still override any of it.
 *
 * An integration, Integration/<Other>/ (ADR 0033), may hold templates/,
 * translations/ and assets/controllers/ of its own. The bundle registers
 * them only when <Other> is installed: the templates under the module's
 * namespace (@<Module>), so a screen keeps its template names wherever it
 * lives, the translations in the messages domain, and the controllers like
 * the module's own (ADR 0038).
 */
abstract class ModuleBundle extends AbstractBundle
{
    private ?ModuleGraph $installed = null;

    public function __construct()
    {
        $this->extensionAlias = 'foresi_'.Container::underscore($this->module());
    }

    /**
     * The module's directory and namespace segment, such as "CashFlow".
     */
    final public function module(): string
    {
        $namespace = substr(static::class, 0, (int) strrpos(static::class, '\\'));

        return substr($namespace, (int) strrpos($namespace, '\\') + 1);
    }

    /**
     * The module's id, such as "cash-flow" for CashFlow.
     */
    final public function id(): ModuleId
    {
        return ModuleId::of(str_replace('_', '-', Container::underscore($this->module())));
    }

    /**
     * The module's root, where the bundle class sits; Symfony's default is
     * the parent of a src/ directory, which a module has none of.
     */
    public function getPath(): string
    {
        return $this->path ??= \dirname((string) new \ReflectionClass(static::class)->getFileName());
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import($this->getPath().'/config/services.php');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $module = $this->module();
        if ($this->hasEntities('Domain')) {
            $builder->prependExtensionConfig('doctrine', ['orm' => ['mappings' => [$module => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => $this->getPath().'/Domain',
                'prefix' => 'Foresi\\'.$module.'\\Domain',
                'alias' => $module,
            ]]]]);
        }
        // A value the module publishes and also stores, such as the frozen party of a document.
        if ($this->hasEntities('Contract')) {
            $builder->prependExtensionConfig('doctrine', ['orm' => ['mappings' => [$module.'Contract' => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => $this->getPath().'/Contract',
                'prefix' => 'Foresi\\'.$module.'\\Contract',
                'alias' => $module.'Contract',
            ]]]]);
        }
        $this->prependControllers($builder, $this->getPath().'/assets/controllers', '');
        $this->prependIntegrations($builder);
        $routing = $this->messageRouting();
        if ([] !== $routing) {
            $builder->prependExtensionConfig('framework', ['messenger' => ['routing' => $routing]]);
        }
        $this->prepend($container, $builder);
    }

    /**
     * The transport of each message class of this module.
     *
     * @return array<class-string, string>
     */
    protected function messageRouting(): array
    {
        return [];
    }

    /**
     * Further configuration the module adds to other bundles, such as a
     * Doctrine type or a middleware.
     */
    protected function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
    }

    /**
     * Whether the integration of this module with $module is loaded: the
     * manifest lists $module as optional and $module is installed. A bundle
     * uses it in {@see prepend()} for configuration that only an integration
     * needs, such as a firewall for screens that exist only with Ui.
     */
    final protected function integrationLoaded(string $module, ContainerBuilder $builder): bool
    {
        $graph = $this->installedModules($builder);
        $own = $this->id();
        if (!$graph->has($own) || !$graph->has(ModuleId::of($module))) {
            return false;
        }

        return [] !== array_filter($graph->get($own)->optional, static fn (ModuleId $optional): bool => $optional->value === $module);
    }

    /**
     * The installed modules; a test bundle replaces them with its own graph.
     */
    protected function installedModules(ContainerBuilder $builder): ModuleGraph
    {
        return $this->installed ??= new InstalledModules($builder->getParameter('kernel.project_dir'))->graph();
    }

    /**
     * Registers the templates, translations and Stimulus controllers of each
     * loaded integration of this module.
     */
    private function prependIntegrations(ContainerBuilder $builder): void
    {
        if (!is_dir($this->getPath().'/'.ModuleIntegrations::DIRECTORY)) {
            return;
        }
        $graph = $this->installedModules($builder);
        foreach (new ModuleIntegrations(\dirname($this->getPath()))->of($graph) as $integration) {
            if (!$integration->loaded || $integration->module->value !== $this->id()->value) {
                continue;
            }
            $templates = $integration->directory.'/templates';
            if (is_dir($templates) && $builder->hasExtension('twig')) {
                $builder->prependExtensionConfig('twig', ['paths' => [$templates => $this->module()]]);
            }
            $translations = $integration->directory.'/translations';
            if (is_dir($translations)) {
                $builder->prependExtensionConfig('framework', ['translator' => ['paths' => [$translations]]]);
            }
            $this->prependControllers($builder, $integration->directory.'/assets/controllers', '/'.$integration->uses->value);
        }
    }

    /**
     * Maps Stimulus controllers under the asset namespace @foresi/<module>
     * (the module's own) or @foresi/<module>/<other> (an integration's), and
     * adds the directory to the controllers Stimulus loads. A controller
     * keeps the name its file gives it, so board_controller.js is still
     * "board".
     */
    private function prependControllers(ContainerBuilder $builder, string $controllers, string $suffix): void
    {
        if (!is_dir($controllers) || !$builder->hasExtension('stimulus')) {
            return;
        }
        $namespace = '@foresi/'.str_replace('_', '-', Container::underscore($this->module())).$suffix;
        $builder->prependExtensionConfig('framework', ['asset_mapper' => ['paths' => [$controllers => $namespace]]]);
        $builder->prependExtensionConfig('stimulus', ['controller_paths' => [$controllers]]);
    }

    /**
     * Whether the directory, Domain/ or Contract/, holds a Doctrine entity or
     * embeddable, so the module needs a mapping of it.
     */
    private function hasEntities(string $directory): bool
    {
        $domain = $this->getPath().'/'.$directory;
        if (!is_dir($domain)) {
            return false;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($domain, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()
                && 1 === preg_match('/#\[ORM\\\\(Entity|Embeddable)\b/', (string) file_get_contents($file->getPathname()))) {
                return true;
            }
        }

        return false;
    }
}
