<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Audit\Application\AuditActor;
use Foresi\Audit\Application\TokenAuditActor;
use Foresi\Identity\Infrastructure\Console\McpStdioSignIn;
use Foresi\Identity\Infrastructure\Idempotency\DbalIdempotencyKeys;
use Foresi\Identity\Infrastructure\Security\ApiTokenHandler;
use Foresi\Identity\Infrastructure\Security\ApiTokenLogin;
use Foresi\Identity\Infrastructure\Security\MembershipActorRole;
use Foresi\Identity\Infrastructure\Security\SecurityAuditActor;
use Foresi\Identity\Infrastructure\Security\TokenApiClient;
use Foresi\Identity\Infrastructure\Security\TokenScopeGuard;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Api\ApiClient;
use Foresi\Shared\Application\Api\IdempotencyKeys;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Mcp\ToolCaller;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/*
 * The services of the Identity module, loaded by Foresi\Identity\IdentityBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Identity\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../IdentityBundle.php']);

    // Identity serves the ports of the shared kernel and of Audit: who acts, in which role, through which token.
    $services->alias(AuditActor::class, SecurityAuditActor::class);
    $services->alias(TokenAuditActor::class, SecurityAuditActor::class);
    $services->alias(ActorRole::class, MembershipActorRole::class);
    $services->alias(ApiClient::class, TokenApiClient::class);
    $services->alias(IdempotencyKeys::class, DbalIdempotencyKeys::class);
    $services->alias(ScopeGuard::class, TokenScopeGuard::class);
    $services->alias(ToolCaller::class, McpStdioSignIn::class);

    // The mcp firewall also accepts the access tokens of OAuth clients (ADR 0036); the api firewall does not.
    $services->set('foresi.mcp_token_handler', ApiTokenHandler::class)->arg('$acceptsOAuth', true);

    if ('test' === $container->env()) {
        // The MCP server on stdio will sign in with it; until then only the tests do.
        $services->set(ApiTokenLogin::class)->public();
    }
    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.password_hashers', PasswordHasherFactoryInterface::class)->public();
        $services->alias('e2e.token_storage', 'security.token_storage')->public();
    }
};
