<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Meetings\Application\ActionItemReads;
use Foresi\Meetings\Application\ActionItemWrites;
use Foresi\Meetings\Application\MeetingHistory;
use Foresi\Meetings\Application\MeetingQueries;
use Foresi\Meetings\Application\MeetingReads;
use Foresi\Meetings\Application\MeetingsStore;
use Foresi\Meetings\Application\MeetingsWork;
use Foresi\Meetings\Application\MeetingVisibility;
use Foresi\Meetings\Application\MeetingWrites;
use Foresi\Meetings\Application\PurgeTrashedMeetings;
use Foresi\Meetings\Application\SeriesReads;
use Foresi\Meetings\Application\SeriesWrites;
use Foresi\Meetings\Infrastructure\Doctrine\DoctrineMeetingQueries;
use Foresi\Meetings\Infrastructure\Doctrine\DoctrineMeetingsStore;
use Foresi\Meetings\Infrastructure\Doctrine\DoctrineMeetingsWork;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Meetings module, loaded by Foresi\Meetings\MeetingsBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Meetings\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../MeetingsBundle.php']);

    $services->alias(MeetingsStore::class, DoctrineMeetingsStore::class);
    $services->alias(MeetingsWork::class, DoctrineMeetingsWork::class);
    $services->alias(MeetingQueries::class, DoctrineMeetingQueries::class);

    if ('test' === $container->env()) {
        // The tests drive the use cases directly.
        foreach ([SeriesWrites::class, SeriesReads::class, MeetingWrites::class, MeetingReads::class, MeetingHistory::class, MeetingVisibility::class, PurgeTrashedMeetings::class, ActionItemWrites::class, ActionItemReads::class] as $service) {
            $services->set($service)->public();
        }
    }
};
