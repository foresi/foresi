<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity;

use Foresi\Identity\Infrastructure\Security\ApiFirewalls;
use Foresi\Identity\Infrastructure\Security\ApiTokenFailure;
use Foresi\Identity\Infrastructure\Security\ApiTokenHandler;
use Foresi\Identity\Infrastructure\Security\RecoveryCodeManager;
use Foresi\Identity\Infrastructure\Security\UserProvider;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * People, their sign-in, roles, invitations and personal API tokens.
 *
 * The bundle declares the firewalls of the instance: REST and MCP sign in with
 * a token on every request and need no screen, so they are always there; the
 * session firewall with its sign-in pages and second factor exists only with
 * Ui (ADR 0038). Symfony takes the firewalls, in their order, from the first
 * configuration that names any, so all of them are declared here; the shared
 * security configuration keeps the password hashers and the access rules.
 */
final class IdentityBundle extends ModuleBundle
{
    public const string SESSION_FIREWALL = 'main';

    protected function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $screens = $this->integrationLoaded('ui', $builder);
        $test = 'test' === $builder->getParameter('kernel.environment');

        $firewalls = [
            // Development tools and static assets are always allowed.
            'dev' => ['pattern' => '^/(_profiler(/|$)|(_wdt|assets|build)/)', 'security' => false],
            // The public routes that are stateless too, such as the health check for an uptime
            // monitor and the inbound mail webhook, which checks its own bearer secret: no user
            // and no session. A module marks its route with the default _public and the option
            // stateless (Foresi\Shared\Infrastructure\Security\PublicRoute).
            'public_stateless' => ['request_matcher' => 'foresi.security.public_stateless_route', 'stateless' => true],
            // REST and MCP sign in with a personal API token on every request: no session, no cookie, no CSRF.
            ApiFirewalls::API => $this->tokenFirewall('^/api/', ApiTokenHandler::class),
            // MCP also takes the access tokens of OAuth clients (ADR 0036).
            ApiFirewalls::MCP => $this->tokenFirewall('^/mcp(/|$)', 'foresi.mcp_token_handler'),
        ];
        if ($screens) {
            $firewalls[self::SESSION_FIREWALL] = [
                'lazy' => true,
                'provider' => 'app_user_provider',
                'form_login' => [
                    'login_path' => 'app_login',
                    'check_path' => 'app_login',
                    'enable_csrf' => true,
                    'default_target_path' => 'app_dashboard',
                ],
                'logout' => ['path' => 'app_logout', 'target' => 'app_login', 'enable_csrf' => true],
                'login_throttling' => [
                    'max_attempts' => 5,
                    'interval' => '1 minute',
                    // In memory in the tests, so attempts never leak between test runs.
                    ...($test ? ['storage_service' => 'test.login_throttling_storage'] : []),
                ],
                // The code step after the password (Integration/Ui/Controller/LoginController).
                'two_factor' => [
                    'auth_form_path' => '2fa_login',
                    'check_path' => '2fa_login_check',
                    'default_target_path' => 'app_dashboard',
                    'enable_csrf' => true,
                    'csrf_token_id' => 'two_factor',
                    'auth_code_parameter_name' => '_auth_code',
                    'trusted_parameter_name' => '_trusted',
                ],
            ];
        }
        $builder->prependExtensionConfig('security', [
            'providers' => ['app_user_provider' => ['id' => UserProvider::class]],
            'firewalls' => $firewalls,
        ]);

        // Two-factor sign-in: a TOTP code from an authenticator app after the password, recovery
        // codes when the phone is lost, and an optional trusted browser.
        // https://symfony.com/bundles/SchebTwoFactorBundle/current/configuration.html
        $builder->prependExtensionConfig('scheb_two_factor', [
            'security_tokens' => [UsernamePasswordToken::class, PostAuthenticationToken::class],
            // A code is accepted once only, so one read over a shoulder never signs in twice.
            'code_reuse_cache' => 'cache.app',
            'code_reuse_cache_duration' => 90,
            'totp' => [
                'enabled' => true,
                'issuer' => '%app.product_name%',
                // Seconds a code stays good past its period, for phones whose clock is slightly off.
                'leeway' => 15,
                ...($screens ? ['template' => '@Identity/two_factor.html.twig'] : []),
            ],
            'backup_codes' => ['enabled' => true, 'manager' => RecoveryCodeManager::class],
            // "Remember this browser": a signed cookie (JWT with APP_SECRET), 30 days; resetting
            // a user's two-factor sign-in invalidates every such cookie.
            'trusted_device' => [
                'enabled' => true,
                'lifetime' => 2592000,
                'extend_lifetime' => false,
                'cookie_name' => 'trusted_device',
                'cookie_secure' => 'auto',
                'cookie_same_site' => 'lax',
                // Test only: the signing key must have 256 bits, more than the test APP_SECRET.
                ...($test ? ['key' => 'test-only-trusted-device-signing-key-0123456789'] : []),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenFirewall(string $pattern, string $handler): array
    {
        return [
            'pattern' => $pattern,
            'stateless' => true,
            'provider' => 'app_user_provider',
            'entry_point' => ApiTokenFailure::class,
            'access_token' => ['token_handler' => $handler, 'failure_handler' => ApiTokenFailure::class],
        ];
    }
}
