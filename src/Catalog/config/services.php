<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Catalog\Application\ProductCatalog;
use Foresi\Catalog\Contract\Products;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Catalog module, loaded by Foresi\Catalog\CatalogBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Catalog\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../CatalogBundle.php']);

    // Other modules, such as Sales, read the products through Foresi\Catalog\Contract\Products.
    $services->alias(Products::class, ProductCatalog::class)->public();
};
