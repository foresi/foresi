<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\ReceivedDocuments\Application\Mail\MailFilingQueue;
use Foresi\ReceivedDocuments\Application\Mail\MailIntakes;
use Foresi\ReceivedDocuments\Application\Reading\ReadDocument;
use Foresi\ReceivedDocuments\Application\RecordReceivedDocument;
use Foresi\ReceivedDocuments\Contract\Reading\DocumentReader;
use Foresi\ReceivedDocuments\Infrastructure\Doctrine\DoctrineMailIntakes;
use Foresi\ReceivedDocuments\Infrastructure\Messenger\MessengerMailFilingQueue;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Purchasing module, loaded by Foresi\ReceivedDocuments\ReceivedDocumentsBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\ReceivedDocuments\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../ReceivedDocumentsBundle.php']);

    // Readers of received documents plug in from any module (Country, an optional one) through
    // this tag, in reader order by tag priority (set per reader).
    $builder->registerForAutoconfiguration(DocumentReader::class)->addTag(DocumentReader::TAG);
    $services->set(ReadDocument::class)->arg('$readers', tagged_iterator(DocumentReader::TAG));

    $services->alias(MailIntakes::class, DoctrineMailIntakes::class);
    $services->alias(MailFilingQueue::class, MessengerMailFilingQueue::class);

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.record_received_document', RecordReceivedDocument::class)->public();
    }
};
