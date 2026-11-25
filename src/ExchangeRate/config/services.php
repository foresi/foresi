<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\ExchangeRate\Application\RateSources;
use Foresi\ExchangeRate\Application\RateStore;
use Foresi\ExchangeRate\Contract\RateRules;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\ExchangeRate\Infrastructure\ChosenRateSource;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the ExchangeRate module, loaded by Foresi\ExchangeRate\ExchangeRateBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\ExchangeRate\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../templates/', '../translations/', '../ExchangeRateBundle.php']);

    // Any module offers a source (Foresi\ExchangeRate\Contract\RateSource), this one the ECB after all others,
    // and a country module its rules (Foresi\ExchangeRate\Contract\RateRules); the firm converts at one source (ADR 0046).
    $services->set(RateSources::class)
        ->arg('$sources', tagged_iterator(RateSource::TAG))
        ->arg('$allRules', tagged_iterator(RateRules::TAG))
        // What it found holds for one request or message.
        ->tag('kernel.reset', ['method' => 'forget']);
    // The one the firm converts at stands for the port in this module, and is no source of its own.
    $services->set(ChosenRateSource::class)->autoconfigure(false);
    $services->alias(RateSource::class, ChosenRateSource::class);

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.rate_store', RateStore::class)->public();
    }
};
