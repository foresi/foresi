<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Calendar\Application\CalendarFeedReader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Calendar module, loaded by Foresi\Calendar\CalendarBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Calendar\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../CalendarBundle.php']);

    // Modules that plan things in time plug in through Foresi\Calendar\Contract\CalendarEntrySource.
    $services->set(CalendarFeedReader::class)
        ->arg('$sources', tagged_iterator('app.calendar_entry_source'));
};
