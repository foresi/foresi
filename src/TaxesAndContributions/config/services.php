<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the TaxesAndContributions module, loaded by
 * Foresi\TaxesAndContributions\TaxesAndContributionsBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\TaxesAndContributions\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../TaxesAndContributionsBundle.php']);

    // Invoicing and Purchasing give the paid amounts through Foresi\TaxesAndContributions\Contract\ActualAmountSource.
    $services->set(Amounts::class)
        ->arg('$sources', tagged_iterator(ActualAmountSource::TAG));
};
