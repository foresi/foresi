<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Documents\Application\DocumentVariables;
use Foresi\Documents\Application\DocumentWrites;
use Foresi\Documents\Application\LinkTargets;
use Foresi\Documents\Contract\DocumentLinkTarget;
use Foresi\Documents\Contract\DocumentVariableSource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Documents\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../DocumentsBundle.php', '../Domain/', '../Contract/']);

    // Modules offer variables and link targets through Foresi\Documents\Contract. The contract is
    // not loaded as services, so its #[AutoconfigureTag] would never apply: tag the implementations here.
    $builder->registerForAutoconfiguration(DocumentVariableSource::class)->addTag(DocumentVariableSource::TAG);
    $builder->registerForAutoconfiguration(DocumentLinkTarget::class)->addTag(DocumentLinkTarget::TAG);
    $services->set(DocumentVariables::class)
        ->arg('$sources', tagged_iterator(DocumentVariableSource::TAG));
    $services->set(LinkTargets::class)
        ->arg('$targets', tagged_iterator(DocumentLinkTarget::TAG));
    $services->set(DocumentWrites::class)
        ->arg('$language', param('kernel.default_locale'));
};
