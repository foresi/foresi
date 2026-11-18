<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Country module, loaded by Foresi\Country\CountryBundle.
 * A country plugs into Party through its tagged interfaces, which Party
 * autoconfigures, and into Invoicing, Banking and ExchangeRate from
 * Integration/, which the kernel loads only when that module is installed.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Country\\', '../')
        ->exclude(['../config/', '../Integration/', '../CountryBundle.php']);

    // The product an ISDOC file names as its issuer (IsdocWriter, in Integration/Invoicing/).
    $container->parameters()->set('country.isdoc_issuing_system', '%app.product_name% %app.version%');
};
