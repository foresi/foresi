<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Timesheet\Application\ManageTime;
use Foresi\Timesheet\Application\ProjectBudgetReads;
use Foresi\Timesheet\Application\ProjectBudgets;
use Foresi\Timesheet\Application\ProjectTimeSettings;
use Foresi\Timesheet\Application\PurgeTrashedTime;
use Foresi\Timesheet\Application\TimeQueries;
use Foresi\Timesheet\Contract\TimeTotals;
use Foresi\Timesheet\Infrastructure\CollectedInvoiceNumbers;
use Foresi\Timesheet\Infrastructure\Directory\LoggedTimeTotals;
use Foresi\Timesheet\Infrastructure\Doctrine\DoctrineTimeQueries;
use Foresi\Timesheet\Infrastructure\InvoiceNumbers;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Timesheet module, loaded by Foresi\Timesheet\TimesheetBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Timesheet\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../TimesheetBundle.php']);

    // A line of invoiced time names its invoice, when the billing of time (Integration/Invoicing/) is loaded.
    $services->alias(InvoiceNumbers::class, CollectedInvoiceNumbers::class);

    if ('test' === $container->env()) {
        // The tests drive the time services directly.
        foreach ([ManageTime::class, PurgeTrashedTime::class, ProjectBudgets::class, ProjectBudgetReads::class, ProjectTimeSettings::class] as $service) {
            $services->set($service)->public();
        }
        $services->alias(TimeQueries::class, DoctrineTimeQueries::class)->public();
        // The contract other modules use, reached by the tests as such a module would.
        $services->alias(TimeTotals::class, LoggedTimeTotals::class)->public();
    }

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.timesheet.manage_time', ManageTime::class)->public();
        $services->alias('e2e.timesheet.project_time_settings', ProjectTimeSettings::class)->public();
    }
};
