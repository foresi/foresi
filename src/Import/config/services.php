<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Import\Application\CancelImport;
use Foresi\Import\Application\CheckImport;
use Foresi\Import\Application\ConfigureImport;
use Foresi\Import\Application\ExportRecords;
use Foresi\Import\Application\ImportErrorFile;
use Foresi\Import\Application\ImportFiles;
use Foresi\Import\Application\ImportLock;
use Foresi\Import\Application\Imports;
use Foresi\Import\Application\PresetRegistry;
use Foresi\Import\Application\ProfileRepository;
use Foresi\Import\Application\RunImport;
use Foresi\Import\Application\StartImport;
use Foresi\Import\Application\Target\TargetRegistry;
use Foresi\Import\Application\UndoImport;
use Foresi\Import\Contract\ImportTarget;
use Foresi\Import\Contract\SourcePreset;
use Foresi\Import\Infrastructure\Doctrine\DoctrineImports;
use Foresi\Import\Infrastructure\Doctrine\DoctrineProfileRepository;
use Foresi\Import\Infrastructure\LocalImportFiles;
use Foresi\Tests\Import\Support\FakeTarget;
use Foresi\Tests\Import\Support\FlakyImportLock;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Import module, loaded by Foresi\Import\ImportBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Import\\', '../')
        ->exclude(['../config/', '../Integration/', '../ImportBundle.php']);

    // Uploaded import sources; never below public/.
    $container->parameters()->set('app.import_files_dir', '%kernel.project_dir%/var/imports');

    // Modules plug into the import by implementing its target contract...
    $builder->registerForAutoconfiguration(ImportTarget::class)->addTag('app.import_target');
    // ...and contribute presets of the known sources they can read.
    $builder->registerForAutoconfiguration(SourcePreset::class)->addTag('app.import_preset');

    $services->set(LocalImportFiles::class)
        ->arg('$root', param('app.import_files_dir'));
    $services->set(TargetRegistry::class)
        ->arg('$targets', tagged_iterator('app.import_target'));
    $services->set(PresetRegistry::class)
        ->arg('$presets', tagged_iterator('app.import_preset'));

    if ('test' === $container->env()) {
        // Lets a test make the gate's lock fail once.
        $services->set(FlakyImportLock::class)->decorate(ImportLock::class);

        // A target living in memory, for the pipeline tests.
        $services->set(FakeTarget::class)->public()->tag('app.import_target');

        // The tests drive the import services directly, not only through the screens.
        foreach ([StartImport::class, ExportRecords::class, ConfigureImport::class, CheckImport::class, RunImport::class, UndoImport::class, CancelImport::class, ImportErrorFile::class] as $service) {
            $services->set($service)->public();
        }

        // Public for the tests, which use the ports directly.
        $services->alias(Imports::class, DoctrineImports::class)->public();
        $services->alias(ProfileRepository::class, DoctrineProfileRepository::class)->public();
        $services->alias(ImportFiles::class, LocalImportFiles::class)->public();
    }
};
