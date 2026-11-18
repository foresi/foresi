<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\ExchangeRate\Application\RateStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the ExchangeRate module, loaded by Foresi\ExchangeRate\ExchangeRateBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\ExchangeRate\\', '../')
        ->exclude(['../config/', '../Integration/', '../ExchangeRateBundle.php']);

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.rate_store', RateStore::class)->public();
    }
};
