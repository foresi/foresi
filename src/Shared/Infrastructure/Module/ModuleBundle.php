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
 * Doctrine mapping of its entities, the Messenger routing of its messages
 * and whatever {@see prepend()} adds. The application's own configuration
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
        if ($this->hasEntities()) {
            $builder->prependExtensionConfig('doctrine', ['orm' => ['mappings' => [$module => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => $this->getPath().'/Domain',
                'prefix' => 'Foresi\\'.$module.'\\Domain',
                'alias' => $module,
            ]]]]);
        }
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
     * Whether Domain/ holds a Doctrine entity or embeddable, so the module
     * needs a mapping.
     */
    private function hasEntities(): bool
    {
        $domain = $this->getPath().'/Domain';
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
