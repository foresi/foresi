<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The vehicles of a firm. Its settings are the bundle's configuration,
 * acme_fleet in config/packages/acme_fleet.yaml of the application:
 *
 *     acme_fleet:
 *         max_vehicles: 40
 */
final class AcmeFleetBundle extends ModuleBundle
{
    public const int MAX_VEHICLES = 25;

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->integerNode('max_vehicles')
                    ->info('The most vehicles the firm keeps; adding one more is refused.')
                    ->min(1)
                    ->defaultValue(self::MAX_VEHICLES)
                ->end()
            ->end();
    }

    /**
     * @param array{max_vehicles: int} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $container->parameters()->set('acme_fleet.max_vehicles', $config['max_vehicles']);
    }
}
