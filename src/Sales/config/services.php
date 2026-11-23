<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Sales\Application\DealLineWrites;
use Foresi\Sales\Application\DealLinkWrites;
use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Application\DealWrites;
use Foresi\Sales\Application\LostReasonWrites;
use Foresi\Sales\Application\SalesStore;
use Foresi\Sales\Application\StageWrites;
use Foresi\Sales\Infrastructure\Doctrine\DoctrineSalesStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Sales module, loaded by Foresi\Sales\SalesBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Sales\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../SalesBundle.php', '../Domain/', '../Contract/']);

    // The use cases are the module's entry points, for its screens, operation handlers and tools
    // alike; kept public so they are there before every surface calls them.
    foreach ([DealWrites::class, DealLineWrites::class, DealLinkWrites::class, StageWrites::class, LostReasonWrites::class, DealReads::class] as $useCase) {
        $services->get($useCase)->public();
    }
    $services->alias(SalesStore::class, DoctrineSalesStore::class)->public();
};
