<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Kernel;

use Foresi\Shared\Domain\Module\InvalidManifest;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Foresi\Shared\Infrastructure\Module\ModuleIntegrations;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

/**
 * The kernel of a Foresi application: an application's Kernel class extends
 * Symfony's kernel and uses this trait, and nothing else.
 *
 * It registers the bundle of every installed module and the integration code
 * of every installed optional dependency, on top of the MicroKernelTrait's
 * config/ of the application.
 */
trait ForesiKernel
{
    use MicroKernelTrait {
        configureContainer as private configureContainerFromFiles;
        registerBundles as private registerConfiguredBundles;
    }

    /**
     * The bundles of config/bundles.php, then the bundle of every installed
     * module (extra.foresi.bundle), each after the modules it requires. No
     * module is listed in config/bundles.php: being installed enables it.
     */
    public function registerBundles(): iterable
    {
        foreach ($this->registerConfiguredBundles() as $bundle) {
            yield $bundle instanceof BundleInterface ? $bundle : throw new \LogicException('config/bundles.php lists a class that is not a bundle.');
        }

        $graph = new InstalledModules($this->getProjectDir())->graph();
        foreach ($graph->dependencyOrder() as $id) {
            $class = $graph->get($id)->bundle;
            if (null === $class) {
                continue;
            }
            $bundle = new $class();
            yield $bundle instanceof ModuleBundle ? $bundle : throw new InvalidManifest(\sprintf('Module %s names the bundle %s, which does not extend %s.', $id->value, $class, ModuleBundle::class));
        }
    }

    /**
     * Resolves the settings that must be right before anything runs, so a wrong
     * one stops the instance with its reason instead of shifting its dates:
     * APP_TIMEZONE.
     */
    public function boot(): void
    {
        parent::boot();
        $this->getContainer()->getParameter('app.timezone');
    }

    /**
     * The configuration files, then the integration code of each module whose
     * optional dependency is installed (ADR 0033). The container is rebuilt
     * when Composer installs or removes a package, so adding a module turns
     * its integrations on.
     */
    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->configureContainerFromFiles($container);

        $projectDir = $this->getProjectDir();
        new ModuleIntegrations()->register(new InstalledModules($projectDir)->graph(), $container);
        $installed = $projectDir.'/vendor/composer/installed.php';
        if (is_file($installed)) {
            $builder->addResource(new FileResource($installed));
        }
    }
}
