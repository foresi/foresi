<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Events\Application\EventSink;
use Foresi\Events\Infrastructure\Dbal\DbalEventRecorder;
use Foresi\Shared\Application\Event\EventOutbox;
use Foresi\Shared\Application\Event\EventRecorder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Events module, loaded by Foresi\Events\EventsBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Events\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../EventsBundle.php']);

    // Takes each event the relay numbers, in its transaction, such as the webhooks.
    $builder->registerForAutoconfiguration(EventSink::class)->addTag(EventSink::TAG);
    // Events serves the outbox of the shared kernel: modules record events, the relay numbers them.
    $services->alias(EventRecorder::class, DbalEventRecorder::class);
    $services->alias(EventOutbox::class, DbalEventRecorder::class);
};
