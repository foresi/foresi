<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\ExchangeRate\Application\RateStore;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\ExchangeRate\Infrastructure\InstalledRateSource;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the ExchangeRate module, loaded by Foresi\ExchangeRate\ExchangeRateBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\ExchangeRate\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../ExchangeRateBundle.php']);

    // A country module offers the source of the daily rates; without one, none are fetched (ADR 0034, rule 3).
    $services->alias(RateSource::class, InstalledRateSource::class);

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.rate_store', RateStore::class)->public();
    }
};
