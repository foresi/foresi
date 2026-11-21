<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\Estimates;
use Foresi\TaxesAndContributions\Application\YearEndForecast;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\ForecastAmountSource;
use Foresi\TaxesAndContributions\Contract\Regime;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
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

    // The same modules give the net-to-gross ratio of each open document of the forecast.
    $services->set(YearEndForecast::class)
        ->arg('$documents', tagged_iterator(ActualAmountSource::TAG));

    // A country gives the rules of a legal form through Foresi\TaxesAndContributions\Contract\Regime;
    // Cash flow, when installed, gives the forecast through Foresi\TaxesAndContributions\Contract\ForecastAmountSource.
    $services->set(Estimates::class)
        ->arg('$regimes', tagged_iterator(Regime::TAG))
        ->arg('$forecasts', tagged_iterator(ForecastAmountSource::TAG))
        ->arg('$yearEnd', service(YearEndForecast::class));
};
