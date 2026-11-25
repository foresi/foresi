<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\AiReader\Application\AiProvider;
use Foresi\AiReader\Application\AiReaderSettings;
use Foresi\AiReader\Infrastructure\UnconfiguredProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

/*
 * The services of the AiReader module, loaded by Foresi\AiReader\AiReaderBundle.
 */
return static function (ContainerConfigurator $container): void {
    // The variables are read when the settings are built, never at compile time, so no key reaches the compiled container.
    foreach (['PROVIDER', 'API_KEY', 'MODEL', 'TRIGGER'] as $name) {
        $container->parameters()->set('env(AI_READER_'.$name.')', '');
    }

    $services = $container->services()->defaults()->autowire()->autoconfigure();
    $services->load('Foresi\\AiReader\\', '../')
        ->exclude(['../config/', '../AiReaderBundle.php']);

    $services->set(AiReaderSettings::class)->args([
        env('AI_READER_PROVIDER'),
        env('AI_READER_API_KEY'),
        env('AI_READER_MODEL'),
        env('AI_READER_TRIGGER'),
    ]);

    // The provider the settings name; a provider adapter replaces this binding.
    $services->alias(AiProvider::class, UnconfiguredProvider::class);
};
