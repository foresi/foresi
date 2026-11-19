<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Identity\Contract\GuestHome;
use Foresi\Work\Application\ManageComments;
use Foresi\Work\Application\ManageLabels;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageSections;
use Foresi\Work\Application\ManageSharing;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Application\ManageTime;
use Foresi\Work\Application\ManageWorkflow;
use Foresi\Work\Application\PurgeDeletedComments;
use Foresi\Work\Application\PurgeDeletedTasks;
use Foresi\Work\Application\PurgeTrashedLabels;
use Foresi\Work\Application\PurgeTrashedTime;
use Foresi\Work\Application\SharedWorkVisibility;
use Foresi\Work\Application\TaskHistory;
use Foresi\Work\Application\TimeQueries;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Contract\InvoiceNumbers;
use Foresi\Work\Infrastructure\CollectedInvoiceNumbers;
use Foresi\Work\Infrastructure\Doctrine\DoctrineTimeQueries;
use Foresi\Work\Infrastructure\Doctrine\DoctrineWorkQueries;
use Foresi\Work\Infrastructure\Identity\SharedWorkGuestHome;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Work module, loaded by Foresi\Work\WorkBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Work\\', '../')
        ->exclude(['../config/', '../Integration/', '../WorkBundle.php']);

    // Seconds between a mention or an assignment and its e-mail, skipped if read in the inbox meanwhile.
    $container->parameters()->set('app.notification_mail_delay', 120);

    // A guest's first page is the work shared with them.
    $services->alias(GuestHome::class, SharedWorkGuestHome::class);
    // A line of invoiced time names its invoice.
    $services->alias(InvoiceNumbers::class, CollectedInvoiceNumbers::class);

    if ('test' === $container->env()) {
        // The tests drive the work services directly; the screens come next.
        foreach ([
            ManageProjects::class, ManageSections::class, ManageWorkflow::class, ManageTasks::class, ManageLabels::class,
            TaskHistory::class, ManageComments::class, PurgeDeletedTasks::class, PurgeDeletedComments::class, PurgeTrashedTime::class, PurgeTrashedLabels::class, ManageSharing::class, ManageTime::class,
        ] as $service) {
            $services->set($service)->public();
        }
        $services->alias(TimeQueries::class, DoctrineTimeQueries::class)->public();
        $services->alias(WorkVisibility::class, SharedWorkVisibility::class)->public();
        $services->alias(WorkQueries::class, DoctrineWorkQueries::class)->public();
    }

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.manage_projects', ManageProjects::class)->public();
        $services->alias('e2e.manage_sections', ManageSections::class)->public();
        $services->alias('e2e.manage_tasks', ManageTasks::class)->public();
        $services->alias('e2e.manage_labels', ManageLabels::class)->public();
        $services->alias('e2e.manage_comments', ManageComments::class)->public();
        $services->alias('e2e.manage_sharing', ManageSharing::class)->public();
        $services->alias('e2e.manage_time', ManageTime::class)->public();
    }
};
