<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\RebillLinks;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Margin module, loaded by Foresi\Margin\MarginBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Margin\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../MarginBundle.php']);
    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.margin_allocation_writes', AllocationWrites::class)->public();
        $services->alias('e2e.margin_rebill_links', RebillLinks::class)->public();
    }
};
