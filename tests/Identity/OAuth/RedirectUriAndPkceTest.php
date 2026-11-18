<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\OAuth;

use Foresi\Identity\Domain\OAuth\Pkce;
use Foresi\Identity\Domain\OAuth\RedirectUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where an OAuth client may send a person back, and the S256 proof of its code (ADR 0036).
 */
final class RedirectUriAndPkceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function redirects(): iterable
    {
        yield 'claude.ai' => ['https://claude.ai/api/mcp/auth_callback', true];
        yield 'loopback' => ['http://localhost:33418/callback', true];
        yield 'loopback ip' => ['http://127.0.0.1/callback', true];
        yield 'loopback ipv6' => ['http://[::1]:8080/cb', true];
        yield 'private-use scheme' => ['cursor://anysphere.cursor-mcp/oauth/callback', true];
        yield 'http elsewhere' => ['http://example.com/callback', false];
        yield 'loopback-looking host' => ['http://localhost.example.com/callback', false];
        yield 'fragment' => ['https://example.com/callback#x', false];
        yield 'credentials' => ['https://user:pass@example.com/callback', false];
        yield 'credentials on loopback' => ['http://user@127.0.0.1/callback', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,x', false];
        yield 'file' => ['file:///etc/passwd', false];
        yield 'no scheme' => ['/callback', false];
        yield 'https without host' => ['https:///callback', false];
        yield 'whitespace' => ['https://example.com/call back', false];
        yield 'empty' => ['', false];
        yield 'too long' => ['https://example.com/'.str_repeat('a', RedirectUri::MAX_LENGTH), false];
    }

    #[DataProvider('redirects')]
    public function testOnlySafeRedirectsAreAllowed(string $uri, bool $allowed): void
    {
        self::assertSame($allowed, RedirectUri::isAllowed($uri));
    }

    public function testARedirectMatchesExactlyExceptForTheLoopbackPort(): void
    {
        self::assertTrue(RedirectUri::matches('https://claude.ai/api/mcp/auth_callback', 'https://claude.ai/api/mcp/auth_callback'));
        self::assertFalse(RedirectUri::matches('https://claude.ai/api/mcp/auth_callback', 'https://claude.ai/api/mcp/auth_callback/'));
        self::assertFalse(RedirectUri::matches('https://claude.ai:443/cb', 'https://claude.ai:8443/cb'));
        self::assertTrue(RedirectUri::matches('http://localhost/callback', 'http://localhost:51234/callback'));
        self::assertTrue(RedirectUri::matches('http://127.0.0.1:1/callback', 'http://127.0.0.1:2/callback'));
        self::assertFalse(RedirectUri::matches('http://127.0.0.1/callback', 'http://127.0.0.1:2/other'));
        self::assertFalse(RedirectUri::matches('http://127.0.0.1/callback', 'http://localhost/callback'));
        self::assertFalse(RedirectUri::matches('http://127.0.0.1/callback', 'https://127.0.0.1/callback'));
    }

    public function testTheConsentScreenShowsTheHostOrTheScheme(): void
    {
        self::assertSame('claude.ai', RedirectUri::displayHost('https://Claude.ai/api/mcp/auth_callback'));
        self::assertSame('127.0.0.1', RedirectUri::displayHost('http://127.0.0.1:3000/cb'));
        self::assertSame('cursor://anysphere.cursor-mcp', RedirectUri::displayHost('cursor://anysphere.cursor-mcp/oauth/callback'));
    }

    public function testS256IsTheBase64UrlSha256OfTheVerifier(): void
    {
        // RFC 7636, appendix B.
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge($verifier));
        self::assertTrue(Pkce::verifies($verifier, 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        self::assertFalse(Pkce::verifies($verifier, Pkce::challenge($verifier.'x')));
        self::assertFalse(Pkce::verifies('short', Pkce::challenge('short')));
        self::assertFalse(Pkce::isVerifier(str_repeat('a', 129)));
        self::assertTrue(Pkce::isVerifier(str_repeat('a', 128)));
        self::assertFalse(Pkce::isVerifier(str_repeat('a', 42).'!'));
        self::assertTrue(Pkce::isChallenge('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        self::assertFalse(Pkce::isChallenge('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-c'));
        self::assertFalse(Pkce::isChallenge('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw+cM'));
    }
}
