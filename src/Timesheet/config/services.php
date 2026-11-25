<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Timesheet\Application\RateResolver;
use Foresi\Timesheet\Contract\ProjectRateSource;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Timesheet module, loaded by Foresi\Timesheet\TimesheetBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Timesheet\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../TimesheetBundle.php']);
    // The rates other modules set for a project, asked before the project's own (Sales: an hourly deal line).
    $services->set(RateResolver::class)->arg('$sources', tagged_iterator(ProjectRateSource::TAG));
};
