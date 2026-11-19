<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Support;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * A container builder to prepend a module bundle's configuration into, and the
 * Doctrine mappings it prepended, for the tests of the bundles of the modules.
 */
trait PrependsModuleBundles
{
    /**
     * @return list<string> the names of the prepended Doctrine mappings, in order
     */
    private function mappings(ContainerBuilder $builder): array
    {
        $names = [];
        foreach ($builder->getExtensionConfig('doctrine') as $config) {
            $orm = $config['orm'] ?? [];
            self::assertIsArray($orm);
            $mappings = $orm['mappings'] ?? [];
            self::assertIsArray($mappings);
            array_push($names, ...array_map(strval(...), array_keys($mappings)));
        }

        return $names;
    }

    private function builder(string $env): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', $env);
        $builder->setParameter('kernel.project_dir', $this->projectDir());

        return $builder;
    }

    private function configurator(ContainerBuilder $builder, string $env): ContainerConfigurator
    {
        $loader = new PhpFileLoader($builder, new FileLocator(), $env);
        $instanceof = [];

        return new ContainerConfigurator($builder, $loader, $instanceof, __FILE__, __FILE__, $env);
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
