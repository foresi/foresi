<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party;

use Foresi\Party\Application\RecheckRegistry;
use Foresi\Party\Infrastructure\Doctrine\VatIdType;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The address book of people and organisations, the firms among them.
 */
final class PartyBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            // Imported contacts are checked in the public registers later, at low priority.
            RecheckRegistry::class => 'registry',
        ];
    }

    protected function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'dbal' => ['types' => ['vat_id' => VatIdType::class]],
        ]);
    }
}
