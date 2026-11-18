<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Module;

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
 */
abstract class ModuleBundle extends AbstractBundle
{
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
        $this->prependControllers($builder);
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
     * Maps the module's Stimulus controllers in assets/controllers/ under
     * the asset namespace @foresi/<module>, and adds the directory to the
     * controllers Stimulus loads. A controller keeps the name its file gives
     * it, so board_controller.js is still "board".
     */
    private function prependControllers(ContainerBuilder $builder): void
    {
        $controllers = $this->getPath().'/assets/controllers';
        if (!is_dir($controllers) || !$builder->hasExtension('stimulus')) {
            return;
        }
        $namespace = '@foresi/'.str_replace('_', '-', Container::underscore($this->module()));
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
