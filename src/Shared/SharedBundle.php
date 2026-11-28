<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared;

use Foresi\Shared\Application\SystemMail;
use Foresi\Shared\Infrastructure\Doctrine\CastTextFunction;
use Foresi\Shared\Infrastructure\Doctrine\CurrencyType;
use Foresi\Shared\Infrastructure\Kernel\DeprecatedTagsPass;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The shared kernel: ids, money, mail and the infrastructure every module
 * builds on.
 */
final class SharedBundle extends ModuleBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // After autoconfiguration has tagged the services (priority 100), before anything collects them.
        $container->addCompilerPass(new DeprecatedTagsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
    }

    protected function messageRouting(): array
    {
        return [
            SystemMail::class => 'mail',
        ];
    }

    protected function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The configuration of the framework every Foresi application runs on. It is
        // prepended, so an application's own config/packages/ overrides any of it.
        if ($builder->hasExtension('framework')) {
            $container->import($this->getPath().'/config/packages/*.yaml');
        }
        // A JSON list column, such as a contact's roles, is matched as text (DqlConditions).
        $builder->prependExtensionConfig('doctrine', ['orm' => ['dql' => ['string_functions' => ['CAST_TEXT' => CastTextFunction::class]]]]);
        $builder->prependExtensionConfig('doctrine', ['dbal' => ['types' => [CurrencyType::NAME => CurrencyType::class]]]);
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
