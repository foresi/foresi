<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Audit;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The audit trail: who changed what, and when.
 */
final class AuditBundle extends ModuleBundle
{
    protected function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ('test' === $container->env()) {
            // The entities only the tests of this module map.
            $builder->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['AuditFixtures' => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => '%kernel.project_dir%/tests/Audit/Fixtures',
                'prefix' => 'Foresi\\Tests\\Audit\\Fixtures',
                'alias' => 'AuditFixtures',
            ]]]]);
        }
    }
}
