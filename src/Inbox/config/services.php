<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Inbox\Application\InboxTransaction;
use Foresi\Inbox\Application\ItemStore;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineInboxTransaction;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Inbox module, loaded by Foresi\Inbox\InboxBundle. The
 * purpose of the inbox addresses (Application\InboxPurpose) is tagged by Mail's
 * autoconfiguration of Foresi\Mail\Contract\InboundPurpose.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Inbox\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../InboxBundle.php']);

    $services->alias(ItemStore::class, DoctrineItemStore::class);
    $services->alias(InboxTransaction::class, DoctrineInboxTransaction::class);
};
