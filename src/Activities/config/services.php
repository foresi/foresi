<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Activities\Application\ActivityTargets;
use Foresi\Activities\Application\LinkableRecordsForOtherModules;
use Foresi\Activities\Application\RecordsForOtherModules;
use Foresi\Activities\Contract\ActivityRecorder;
use Foresi\Activities\Contract\ActivityTarget;
use Foresi\Activities\Contract\LinkableRecords;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Activities\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../ActivitiesBundle.php', '../Domain/', '../Contract/']);

    // Modules offer the records activities link to through Foresi\Activities\Contract\ActivityTarget.
    // The contract is not loaded as services, so its #[AutoconfigureTag] would never apply: tag the
    // implementations here.
    $builder->registerForAutoconfiguration(ActivityTarget::class)->addTag(ActivityTarget::TAG);
    $services->set(ActivityTargets::class)
        ->arg('$targets', tagged_iterator(ActivityTarget::TAG));
    // Other modules record an activity through the contract (Inbox).
    $services->alias(ActivityRecorder::class, RecordsForOtherModules::class);
    $services->alias(LinkableRecords::class, LinkableRecordsForOtherModules::class);
};
