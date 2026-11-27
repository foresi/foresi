<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Shared\Application\View\ListTables;
use Foresi\Tests\Ui\Support\ShellProbeController;
use Foresi\Tests\Ui\Support\TestNavigationProvider;
use Foresi\Tests\Ui\Support\TestPersonPhotos;
use Foresi\Ui\Contract\PersonPhotos;
use Foresi\Ui\Controller\CatalogueController;
use Foresi\Ui\View\ListTable;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Ui module, loaded by Foresi\Ui\UiBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Ui\\', '../')
        ->exclude(['../config/', '../Integration/', '../UiBundle.php', '../Controller/CatalogueController.php']);
    // The tables with views of the pages an integration adds to the interface (Shared\Application\View\ListTables).
    $services->alias(ListTables::class, ListTable::class);

    if (\in_array($container->env(), ['dev', 'test', 'e2e'], true)) {
        // Development tool: registered, and so routable, outside production only.
        $services->set(CatalogueController::class);
    }
    if ('test' === $container->env()) {
        // Stands in for a plugin adding a sidebar item.
        $services->set(TestNavigationProvider::class);
        // Stands in for the module that keeps the people's photos.
        $services->set(TestPersonPhotos::class)->autoconfigure(false)->tag(PersonPhotos::TAG, ['priority' => 100]);
        $services->set(ShellProbeController::class);
    }
};
