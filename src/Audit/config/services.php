<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Audit\Application\AuditTrail;
use Foresi\Audit\Infrastructure\Doctrine\DoctrineAuditTrail;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Audit module, loaded by Foresi\Audit\AuditBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Audit\\', '../')
        ->exclude(['../config/', '../Integration/', '../AuditBundle.php']);

    // Application classes stay framework-free, so the reset tag is declared here:
    // long-running workers and Messenger never carry state into the next job.
    $services->set(AuditCorrelation::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    if ('test' === $container->env()) {
        // The generic audit query; the fake import target answers changedSince()
        // with it, while targets of the application check their own entities.
        $services->alias(AuditTrail::class, DoctrineAuditTrail::class)->public();
    }
};
