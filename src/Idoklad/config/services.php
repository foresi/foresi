<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Idoklad\Application\ConnectIdoklad;
use Foresi\Idoklad\Application\IdokladClient;
use Foresi\Idoklad\Application\IdokladConnections;
use Foresi\Idoklad\Application\IdokladFiles;
use Foresi\Idoklad\Application\IdokladJobs;
use Foresi\Idoklad\Application\IdokladQueue;
use Foresi\Idoklad\Application\IdokladSnapshot;
use Foresi\Idoklad\Application\Import\ContactNames;
use Foresi\Idoklad\Application\Import\IdokladImportRuns;
use Foresi\Idoklad\Application\Import\IdokladLinks;
use Foresi\Idoklad\Application\Import\ImportStage;
use Foresi\Idoklad\Application\Import\ImportTransaction;
use Foresi\Idoklad\Application\Import\RunIdokladImport;
use Foresi\Idoklad\Infrastructure\Doctrine\DoctrineContactNames;
use Foresi\Idoklad\Infrastructure\Doctrine\DoctrineIdokladConnections;
use Foresi\Idoklad\Infrastructure\Doctrine\DoctrineIdokladImportRuns;
use Foresi\Idoklad\Infrastructure\Doctrine\DoctrineIdokladJobs;
use Foresi\Idoklad\Infrastructure\Doctrine\DoctrineIdokladLinks;
use Foresi\Idoklad\Infrastructure\Doctrine\DoctrineIdokladSnapshot;
use Foresi\Idoklad\Infrastructure\Doctrine\DoctrineImportTransaction;
use Foresi\Idoklad\Infrastructure\Files\DiskIdokladFiles;
use Foresi\Idoklad\Infrastructure\Http\HttpIdokladClient;
use Foresi\Idoklad\Infrastructure\Messenger\MessengerIdokladQueue;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Idoklad module, loaded by Foresi\Idoklad\IdokladBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Idoklad\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../IdokladBundle.php']);

    // The PDFs pulled from iDoklad accounts; never below public/. Back it up with the database.
    $container->parameters()->set('app.idoklad_files_dir', '%kernel.share_dir%/idoklad-documents');

    // The stages of an iDoklad import; each one orders itself.
    $builder->registerForAutoconfiguration(ImportStage::class)->addTag(ImportStage::TAG);

    // The importer reads iDoklad accounts; only GET of read-only paths, see ReadOnlyPaths.
    $services->alias(IdokladClient::class, HttpIdokladClient::class);
    $services->alias(IdokladConnections::class, DoctrineIdokladConnections::class);
    $services->alias(IdokladSnapshot::class, DoctrineIdokladSnapshot::class);
    $services->alias(IdokladFiles::class, DiskIdokladFiles::class);
    $services->set(DiskIdokladFiles::class)
        ->arg('$root', param('app.idoklad_files_dir'));
    $services->alias(IdokladJobs::class, DoctrineIdokladJobs::class);
    $services->alias(IdokladQueue::class, MessengerIdokladQueue::class);
    $services->alias(IdokladLinks::class, DoctrineIdokladLinks::class);
    $services->alias(IdokladImportRuns::class, DoctrineIdokladImportRuns::class);
    $services->alias(ImportTransaction::class, DoctrineImportTransaction::class);
    $services->alias(ContactNames::class, DoctrineContactNames::class);
    $services->set(RunIdokladImport::class)
        ->arg('$stages', tagged_iterator(ImportStage::TAG));

    // Declared here (private) so PHPStan, which reads the dev container, knows the
    // id used by the tests; it is made public in the test env below.
    $services->alias('test.idoklad_import', RunIdokladImport::class);

    if ('test' === $container->env()) {
        // The PDFs inside the test cache, so a test never writes to the real share directory.
        $container->parameters()->set('app.idoklad_files_dir', '%kernel.cache_dir%/idoklad-documents');

        // Public for the iDoklad tests, which pull through a mocked HTTP client.
        $services->alias(IdokladConnections::class, DoctrineIdokladConnections::class)->public();
        $services->alias(IdokladSnapshot::class, DoctrineIdokladSnapshot::class)->public();
        $services->alias(IdokladFiles::class, DiskIdokladFiles::class)->public();
        $services->set(ConnectIdoklad::class)->public();

        // Nothing calls the iDoklad import yet; the screens that will are on their way.
        $services->alias('test.idoklad_import', RunIdokladImport::class)->public();
    }

    if ('e2e' === $container->env()) {
        // The PDFs of the seeded iDoklad pull, gone with the e2e cache that serve.sh starts from.
        $container->parameters()->set('app.idoklad_files_dir', '%kernel.cache_dir%/idoklad-documents');

        // The seed stores an iDoklad pull; no browser test ever reaches iDoklad. A client with no
        // responses fails every request, so a pull or a key check started in a spec fails at once.
        $services->set(HttpIdokladClient::class)
            ->arg('$http', inline_service(MockHttpClient::class)->args([[]]));
    }
};
