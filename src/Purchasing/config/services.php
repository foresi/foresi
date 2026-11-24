<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Purchasing\Application\Reading\DocumentReader;
use Foresi\Purchasing\Application\RecordReceivedDocument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Purchasing module, loaded by Foresi\Purchasing\PurchasingBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Purchasing\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../PurchasingBundle.php']);

    // Readers of received documents plug in from any module (Country, an optional one) through
    // this tag, in reader order by tag priority (set per reader).
    $builder->registerForAutoconfiguration(DocumentReader::class)->addTag('foresi.purchasing.document_reader');

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.record_received_document', RecordReceivedDocument::class)->public();
    }
};
