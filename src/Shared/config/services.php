<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Application\Sealer;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Infrastructure\Crypto\SodiumSealer;
use Foresi\Shared\Infrastructure\Registry\PublicRegistryClient;
use Foresi\Shared\Infrastructure\Registry\RegistryCache;
use Foresi\Shared\Infrastructure\Security\PublicRoute;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * The services of the Shared module, loaded by Foresi\Shared\SharedBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Shared\\', '../')
        ->exclude(['../config/', '../Integration/', '../SharedBundle.php']);

    $container->parameters()
        // Error mails to the operator; both empty sends none (docs/logging.md).
        ->set('env(OPERATOR_MAILER_DSN)', '')
        ->set('env(OPERATOR_EMAIL)', '')
        // Mail of the installation itself, such as invitations; empty sends none (docs/operations.md).
        ->set('env(SYSTEM_MAILER_DSN)', '')
        ->set('env(SYSTEM_MAIL_FROM)', '');

    $services->set(RegistryCache::class)
        ->arg('$registryCache', service('cache.registry'));
    $services->set(PublicRegistryClient::class)
        ->arg('$registryOutgoingLimiter', service('limiter.registry_outgoing'));
    $services->alias(Sealer::class, SodiumSealer::class);

    // A module's lists that saved views apply to, whichever module declares them.
    $builder->registerForAutoconfiguration(ListDefinitionSource::class)->addTag(ListDefinitionSource::TAG);

    // The firewall of the public routes that are stateless too (config/packages/security.yaml).
    $services->set('foresi.security.public_stateless_route', PublicRoute::class)
        ->arg('$stateless', true);

    if ('e2e' === $container->env()) {
        // A public handle for tests/e2e/seed.php.
        $services->alias('e2e.entity_manager', EntityManagerInterface::class)->public();
    }
};
