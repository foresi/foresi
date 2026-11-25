<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Attachment\Application\AttachmentAccess;
use Foresi\Attachment\Application\AttachmentPermissions;
use Foresi\Attachment\Application\UploadAttachment;
use Foresi\Attachment\Infrastructure\Storage\DiskAttachmentFiles;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Attachment module, loaded by Foresi\Attachment\AttachmentBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Attachment\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../AttachmentBundle.php']);

    // Files attached to records; never below public/. Back it up with the database.
    $container->parameters()->set('app.attachments_dir', 'e2e' === $container->env()
        ? '%kernel.cache_dir%/attachments'
        : '%kernel.share_dir%/attachments');

    // The module that owns a kind of record decides who reaches its attachments.
    $builder->registerForAutoconfiguration(AttachmentAccess::class)->addTag(AttachmentAccess::TAG);
    $services->set(AttachmentPermissions::class)
        ->arg('$accesses', tagged_iterator(AttachmentAccess::TAG));
    $services->set(DiskAttachmentFiles::class)
        ->arg('$root', param('app.attachments_dir'));

    if ('e2e' === $container->env()) {
        // A public handle for tests/e2e/seed.php.
        $services->alias('e2e.upload_attachment', UploadAttachment::class)->public();
    }
};
