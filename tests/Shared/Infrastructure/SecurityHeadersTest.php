<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure;

use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class SecurityHeadersTest extends ApiTestCase
{
    private const string POLICY = "default-src 'self'; script-src 'self' 'nonce-%1\$s' 'strict-dynamic'; style-src 'self' 'nonce-%1\$s'; style-src-attr 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";

    private const string POLICY_WITHOUT_NONCE = "default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";

    public function testASignedInScreenRunsOnlyTheScriptsOfItsNonce(): void
    {
        $this->client->loginUser(new SecurityUser($this->user->id(), $this->user->email(), $this->user->passwordHash()), 'main');
        $crawler = $this->client->request('GET', '/contacts');

        self::assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        $nonce = (string) $crawler->filter('meta[name="csp-nonce"]')->attr('nonce');
        self::assertMatchesRegularExpression('~^[A-Za-z0-9+/]{22}==$~', $nonce);
        self::assertSame(\sprintf(self::POLICY, $nonce), $headers->get('Content-Security-Policy'));
        // Both importmap scripts carry it, and nothing else inline runs.
        self::assertSame([$nonce, $nonce], $crawler->filter('script')->each(static fn ($script): string => (string) $script->attr('nonce')));
        $this->assertCommonHeaders($headers);

        // Every response has a nonce of its own.
        $again = $this->client->request('GET', '/contacts');
        self::assertNotSame($nonce, $again->filter('meta[name="csp-nonce"]')->attr('nonce'));
    }

    public function testThePublicPagesAndErrorsCarryTheHeaders(): void
    {
        $this->client->request('GET', '/login');
        $headers = $this->client->getResponse()->headers;
        self::assertStringStartsWith("default-src 'self'; script-src 'self' 'nonce-", (string) $headers->get('Content-Security-Policy'));
        $this->assertCommonHeaders($headers);

        // In debug an error is Symfony's exception page, which inlines its scripts: no policy, the rest stays.
        $this->client->request('GET', '/nothing-here');
        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->has('X-Debug-Exception'));
        self::assertFalse($headers->has('Content-Security-Policy'));
        $this->assertCommonHeaders($headers);
    }

    public function testTheApiCarriesTheHeadersWithAPolicyThatRunsNoInlineScript(): void
    {
        foreach (['/api/v1/contacts', '/api/v1/openapi.json'] as $url) {
            $this->get($url);
            self::assertResponseIsSuccessful();
            $headers = $this->client->getResponse()->headers;
            self::assertSame(self::POLICY_WITHOUT_NONCE, $headers->get('Content-Security-Policy'), $url);
            $this->assertCommonHeaders($headers, $url);
        }
    }

    public function testHstsIsSentOverHttpsOnly(): void
    {
        $this->client->request('GET', '/login');
        self::assertFalse($this->client->getResponse()->headers->has('Strict-Transport-Security'));

        $this->client->request('GET', '/login', server: ['HTTPS' => 'on']);
        self::assertSame('max-age=31536000; includeSubDomains', $this->client->getResponse()->headers->get('Strict-Transport-Security'));
    }

    private function assertCommonHeaders(ResponseHeaderBag $headers, string $url = ''): void
    {
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $url);
        self::assertSame('SAMEORIGIN', $headers->get('X-Frame-Options'), $url);
        self::assertSame('same-origin', $headers->get('Referrer-Policy'), $url);
        self::assertSame('same-origin', $headers->get('Cross-Origin-Opener-Policy'), $url);
        self::assertStringContainsString('camera=()', (string) $headers->get('Permissions-Policy'), $url);
    }
}
