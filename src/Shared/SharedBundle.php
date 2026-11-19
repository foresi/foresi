<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared;

use Foresi\Shared\Application\SystemMail;
use Foresi\Shared\Infrastructure\Doctrine\CastTextFunction;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The shared kernel: ids, money, mail and the infrastructure every module
 * builds on.
 */
final class SharedBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            SystemMail::class => 'mail',
        ];
    }

    protected function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // A JSON list column, such as a contact's roles, is matched as text (DqlConditions).
        $builder->prependExtensionConfig('doctrine', ['orm' => ['dql' => ['string_functions' => ['CAST_TEXT' => CastTextFunction::class]]]]);
        if ('test' === $container->env()) {
            // The entities only the tests of this module map.
            $builder->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['SharedFixtures' => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => '%kernel.project_dir%/tests/Shared/Fixtures',
                'prefix' => 'Foresi\\Tests\\Shared\\Fixtures',
                'alias' => 'SharedFixtures',
            ]]]]);
        }
    }
}
