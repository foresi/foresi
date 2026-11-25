<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Inbox\Application\InboxTransaction;
use Foresi\Inbox\Application\ItemsForOtherModules;
use Foresi\Inbox\Application\ItemStore;
use Foresi\Inbox\Contract\InboxItems;
use Foresi\Inbox\Contract\RecordSuggester;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineInboxTransaction;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Inbox module, loaded by Foresi\Inbox\InboxBundle. The
 * purpose of the inbox addresses (Application\InboxPurpose) is tagged by Mail's
 * autoconfiguration of Foresi\Mail\Contract\InboundPurpose.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Inbox\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../InboxBundle.php', '../Domain/', '../Contract/']);

    $services->alias(ItemStore::class, DoctrineItemStore::class);
    $services->alias(InboxTransaction::class, DoctrineInboxTransaction::class);

    // Modules propose the record an item is about through Foresi\Inbox\Contract\RecordSuggester.
    // The contract is not loaded as services, so its #[AutoconfigureTag] would never apply: tag the
    // implementations here.
    $builder->registerForAutoconfiguration(RecordSuggester::class)->addTag(RecordSuggester::TAG);
    $services->set(ItemsForOtherModules::class)
        ->arg('$suggesters', tagged_iterator(RecordSuggester::TAG));
    $alias = $services->alias(InboxItems::class, ItemsForOtherModules::class);
    if ('test' === $container->env()) {
        // A contract nothing in a test boot uses yet, reached by the Inbox tests.
        $alias->public();
    }
};
