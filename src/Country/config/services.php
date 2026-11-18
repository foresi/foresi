<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Country\CzechRepublic\ExchangeRate\CnbRateSource;
use Foresi\Country\CzechRepublic\Invoicing\IsdocWriter;
use Foresi\ExchangeRate\Application\RateSource;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Country module, loaded by Foresi\Country\CountryBundle.
 * A country plugs into Party, Invoicing and Banking through their tagged
 * interfaces, which the owning modules autoconfigure.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Country\\', '../')
        ->exclude(['../config/', '../Integration/', '../CountryBundle.php']);

    // The daily rates come from the Czech National Bank.
    $services->alias(RateSource::class, CnbRateSource::class);

    $services->set(IsdocWriter::class)
        ->arg('$issuingSystem', '%app.product_name% %app.version%');
};
