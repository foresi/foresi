<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Instance\Application\ConfiguredZone;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Instance\Application\Schedule\OperatorTask;
use Foresi\Instance\Application\Schedule\ScheduledJob;
use Foresi\Instance\Application\Schedule\ScheduledJobRuns;
use Foresi\Instance\Application\ZoneFallback;
use Foresi\Instance\Infrastructure\CollectedZoneFallback;
use Foresi\Instance\Infrastructure\Schedule\DoctrineScheduledJobRuns;
use Foresi\Tests\Instance\Support\CountingJob;
use Foresi\Tests\Instance\Support\CountingOperatorTask;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

/*
 * The services of the Instance module, loaded by Foresi\Instance\InstanceBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Instance\\', '../')
        ->exclude(['../config/', '../Integration/', '../InstanceBundle.php']);

    // The health check is degraded when less of the data directory's disk is free, in percent.
    $container->parameters()->set('env(HEALTH_DISK_MIN_FREE_PERCENT)', '5');

    // Timed work done once per period, and timed work of the installation; see Foresi\Instance\Application\Schedule.
    $builder->registerForAutoconfiguration(ScheduledJob::class)->addTag('app.scheduled_job');
    $builder->registerForAutoconfiguration(OperatorTask::class)->addTag('app.operator_task');
    // Modules built on Instance may know its zone, such as Invoicing from a firm's country (ADR 0034, rule 3).
    $builder->registerForAutoconfiguration(ZoneFallback::class)->addTag('app.zone_fallback');
    $services->alias(ZoneFallback::class, CollectedZoneFallback::class);

    $services->set(ConfiguredZone::class)
        ->arg('$name', param('app.timezone'));

    if ('test' === $container->env()) {
        // A scheduled job that counts its runs, and the run records it is claimed through.
        $services->set(CountingJob::class)->tag('app.scheduled_job');
        $services->set(CountingOperatorTask::class)->tag('app.operator_task');
        $services->alias(ScheduledJobRuns::class, DoctrineScheduledJobRuns::class)->public();
        $services->set(InstanceClock::class)->public();
    }
    if ('e2e' === $container->env()) {
        $services->alias('e2e.instance_clock', InstanceClock::class)->public();
    }
};
