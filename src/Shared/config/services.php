<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Application\Http\ContentSecurityPolicySource;
use Foresi\Shared\Application\Sealer;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Infrastructure\Crypto\SodiumSealer;
use Foresi\Shared\Infrastructure\Doctrine\DoctrineTransactionalWork;
use Foresi\Shared\Infrastructure\Field\DoctrineFieldSource;
use Foresi\Shared\Infrastructure\Http\ContentSecurityPolicy;
use Foresi\Shared\Infrastructure\Mcp\ReadToolLoader;
use Foresi\Shared\Infrastructure\Migration\ModuleVersionComparator;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Shared\Infrastructure\Registry\PublicRegistryClient;
use Foresi\Shared\Infrastructure\Registry\RegistryCache;
use Foresi\Shared\Infrastructure\Security\PublicRoute;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * The services of the Shared module, loaded by Foresi\Shared\SharedBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Shared\\', '../')
        ->exclude([
            '../config/', '../Integration/', '../migrations/', '../SharedBundle.php', '../deprecated.php',
            // The deprecated names of what moved into Contract (ADR 0044): loading one reports a deprecation.
            '../Application/{Refused,RefusalKind}.php',
            '../Application/Api/{Operation,Field,Omitted}.php',
            '../Application/Event/{DomainEventListener,RecordedEvent,EventRecorder}.php',
            '../Infrastructure/Mcp/{ReadTool,WriteTool,ToolOutput,ToolRefusal,StaffOnlyTool}.php',
            '../Infrastructure/Operation/{OperationHandler,OperationOutput}.php',
        ]);

    $container->parameters()
        // Error mails to the operator; both empty sends none (docs/logging.md).
        ->set('env(OPERATOR_MAILER_DSN)', '')
        ->set('env(OPERATOR_EMAIL)', '')
        // Mail of the installation itself, such as invitations; empty sends none (docs/operations.md).
        ->set('env(SYSTEM_MAILER_DSN)', '')
        ->set('env(SYSTEM_MAIL_FROM)', '')
        // The security headers (docs/operations.md, "Security headers"). HSTS is sent over HTTPS
        // only; development keeps it off so that a local https server does not pin localhost.
        ->set('env(HSTS_MAX_AGE)', 'dev' === $container->env() ? '0' : '31536000')
        ->set('env(HSTS_INCLUDE_SUBDOMAINS)', '1')
        ->set('env(CSP_REPORT_URI)', '')
        ->set('foresi.hsts.max_age', '%env(int:HSTS_MAX_AGE)%')
        ->set('foresi.hsts.include_subdomains', '%env(bool:HSTS_INCLUDE_SUBDOMAINS)%');

    $services->set(RegistryCache::class)
        ->arg('$registryCache', service('cache.registry'));
    $services->set(PublicRegistryClient::class)
        ->arg('$registryOutgoingLimiter', service('limiter.registry_outgoing'));
    $services->alias(Sealer::class, SodiumSealer::class);

    // The MCP tools are named after the module each belongs to.
    $services->set(ReadToolLoader::class)
        ->arg('$modules', inline_service(ModuleGraph::class)->factory([service(InstalledModules::class), 'graph']));

    // The custom fields are keyed by the module that declares them (ADR 0045).
    $services->set(DoctrineFieldSource::class)
        ->arg('$modules', inline_service(ModuleGraph::class)->factory([service(InstalledModules::class), 'graph']));
    if ('test' === $container->env()) {
        // Public for the tests, which use the port directly.
        $services->alias(TransactionalWork::class, DoctrineTransactionalWork::class)->public();
        // A record kind only the tests have, with custom fields of every kind of a module only
        // the tests have (tests/Shared/Fixtures/Field), so they change no list of the application.
        $services->set(Foresi\Tests\Shared\Fixtures\Field\FixtureNoteRecord::class);
        $services->set(Foresi\Tests\Shared\Fixtures\Field\LentFields::class)->public();
        $services->set(DoctrineFieldSource::class)
            ->arg('$modules', inline_service(ModuleGraph::class)->factory(Foresi\Tests\Shared\Fixtures\Field\FixtureModules::graph(...))->args([service(InstalledModules::class)]));
    }

    // The order of the migrations of all modules (config/packages/doctrine_migrations.yaml).
    $services->set(ModuleVersionComparator::class)
        ->arg('$modules', inline_service(ModuleGraph::class)->factory([service(InstalledModules::class), 'graph']));

    // A module's lists that saved views apply to, whichever module declares them.
    $builder->registerForAutoconfiguration(ListDefinitionSource::class)->addTag(ListDefinitionSource::TAG);

    // What modules add to the Content-Security-Policy of the pages.
    $builder->registerForAutoconfiguration(ContentSecurityPolicySource::class)->addTag(ContentSecurityPolicySource::TAG);
    $services->set(ContentSecurityPolicy::class)
        ->arg('$reportUri', '%env(CSP_REPORT_URI)%');

    // The firewall of the public routes that are stateless too (config/packages/security.yaml).
    $services->set('foresi.security.public_stateless_route', PublicRoute::class)
        ->arg('$stateless', true);

    if ('e2e' === $container->env()) {
        // A public handle for tests/e2e/seed.php.
        $services->alias('e2e.entity_manager', EntityManagerInterface::class)->public();
    }
};
