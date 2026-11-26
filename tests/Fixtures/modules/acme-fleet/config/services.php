<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of acme/fleet, loaded by Acme\Fleet\AcmeFleetBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    $services->load('Acme\\Fleet\\', '../')
        ->exclude(['../config/', '../migrations/', '../templates/', '../tests/', '../translations/', '../AcmeFleetBundle.php']);
};
