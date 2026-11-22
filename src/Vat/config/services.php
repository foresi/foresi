<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Vat\Application\VatRules;
use Foresi\Vat\Contract\VatCountryRules;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the VAT module, loaded by Foresi\Vat\VatBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Vat\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../VatBundle.php']);

    // A country gives its VAT rules through Foresi\Vat\Contract\VatCountryRules.
    $services->set(VatRules::class)
        ->arg('$rules', tagged_iterator(VatCountryRules::TAG));
};
