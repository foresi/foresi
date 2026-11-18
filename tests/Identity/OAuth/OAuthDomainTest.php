<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\OAuth;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\OAuth\AuthorizationCode;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\OAuth\Pkce;
use Foresi\Identity\Domain\OAuth\RedirectUri;
use Foresi\Identity\Domain\OAuth\RefreshToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules the OAuth entities keep on their own (ADR 0036).
 */
final class OAuthDomainTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /**
     * @return iterable<string, array{string}>
     */
    public static function badHashes(): iterable
    {
        yield 'prefixed' => ['x'.self::HASH];
        yield 'suffixed' => [self::HASH.'x'];
        yield 'upper case' => [strtoupper(self::HASH)];
    }

    #[DataProvider('badHashes')]
    public function testACodeKeepsOnlyAHash(string $hash): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->code($hash, [ApiScope::read()]);
    }

    #[DataProvider('badHashes')]
    public function testARefreshTokenKeepsOnlyAHash(string $hash): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RefreshToken($hash, 'token', new \DateTimeImmutable());
    }

    public function testACodeGrantsEachScopeOnce(): void
    {
        $code = $this->code(self::HASH, [ApiScope::read(), ApiScope::from('contacts:write'), ApiScope::read()]);

        self::assertSame(['read', 'contacts:write'], array_map(static fn (ApiScope $scope): string => $scope->value, $code->scopes()));
        self::assertFalse($code->isUsed());
        self::assertNull($code->apiTokenId());
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testACodeNeedsAChallengeAndAScope(): void
    {
        foreach ([[self::HASH, 'plain', [ApiScope::read()]], [self::HASH, self::CHALLENGE, []]] as [$hash, $challenge, $scopes]) {
            try {
                new AuthorizationCode($hash, 'client', 'user', 'https://client.example.com/cb', $challenge, $scopes, 'https://foresi.example.com/mcp', new \DateTimeImmutable());
                self::fail('The code is refused.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testACodeExpiresAfterFiveMinutes(): void
    {
        $code = $this->code(self::HASH, [ApiScope::read()]);

        self::assertFalse($code->isExpired(new \DateTimeImmutable('2026-11-18 10:04:59')));
        self::assertTrue($code->isExpired(new \DateTimeImmutable('2026-11-18 10:05:00')));
    }

    public function testARefreshTokenExpiresAfterThirtyDays(): void
    {
        $token = new RefreshToken(self::HASH, 'token', new \DateTimeImmutable('2026-11-18 10:00:00'));

        self::assertFalse($token->isExpired(new \DateTimeImmutable('2026-12-18 09:59:59')));
        self::assertTrue($token->isExpired(new \DateTimeImmutable('2026-12-18 10:00:00')));
        self::assertFalse($token->isUsed());
        self::assertSame('token', $token->apiTokenId());
    }

    public function testAClientIdHasALimit(): void
    {
        self::assertSame(OAuthClient::CLIENT_ID_MAX_LENGTH, \strlen($this->client(str_repeat('a', OAuthClient::CLIENT_ID_MAX_LENGTH))->clientId()));
        foreach (['', str_repeat('a', OAuthClient::CLIENT_ID_MAX_LENGTH + 1)] as $clientId) {
            try {
                $this->client($clientId);
                self::fail('The client id is refused.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAClientNameIsTidiedAndShortened(): void
    {
        self::assertSame('Claude Code', $this->client('c', "  Claude \n\t Code  ")->name());
        self::assertSame(str_repeat('č', OAuthClient::NAME_MAX_LENGTH), $this->client('c', str_repeat('č', OAuthClient::NAME_MAX_LENGTH + 5))->name());
    }

    public function testAClientKeepsItsRedirectsOnce(): void
    {
        $client = $this->client('c', 'Name', ['https://a.example.com/cb', 'https://b.example.com/cb', 'https://a.example.com/cb']);

        self::assertSame(['https://a.example.com/cb', 'https://b.example.com/cb'], $client->redirectUris());
        self::assertTrue($client->allowsRedirect('https://b.example.com/cb'));
        self::assertFalse($client->allowsRedirect('https://c.example.com/cb'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function badDescriptions(): iterable
    {
        yield 'blank name' => [' ', ['https://a.example.com/cb']];
        yield 'no redirects' => ['Name', []];
        yield 'too many redirects' => ['Name', array_map(static fn (int $i): string => 'https://a.example.com/'.$i, range(0, OAuthClient::MAX_REDIRECT_URIS))];
        yield 'unsafe redirect' => ['Name', ['https://a.example.com/cb', 'javascript:alert(1)']];
    }

    /**
     * @param list<string> $redirectUris
     */
    #[DataProvider('badDescriptions')]
    public function testAClientDescriptionKeepsTheRules(string $name, array $redirectUris): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client('c', $name, $redirectUris);
    }

    public function testAClientMayHaveTenRedirects(): void
    {
        self::assertCount(OAuthClient::MAX_REDIRECT_URIS, $this->client('c', 'Name', array_map(static fn (int $i): string => 'https://a.example.com/'.$i, range(1, OAuthClient::MAX_REDIRECT_URIS)))->redirectUris());
    }

    public function testOnlyAMetadataDocumentVouchesForAHost(): void
    {
        $url = 'https://client.example.com/metadata.json';

        self::assertSame('client.example.com', new OAuthClient($url, OAuthClientKind::Metadata, 'Name', ['https://a.example.com/cb'], new \DateTimeImmutable())->verifiedHost());
        self::assertNull(new OAuthClient($url, OAuthClientKind::Registered, 'Name', ['https://a.example.com/cb'], new \DateTimeImmutable())->verifiedHost());
    }

    public function testAChallengeIsExactlyFortyThreeCharacters(): void
    {
        self::assertTrue(Pkce::isChallenge(self::CHALLENGE));
        self::assertFalse(Pkce::isChallenge('!'.self::CHALLENGE));
        self::assertFalse(Pkce::isChallenge(self::CHALLENGE.'!'));
        self::assertFalse(Pkce::isChallenge(substr(self::CHALLENGE, 1)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function redirects(): iterable
    {
        yield 'longest' => ['https://example.com/'.str_repeat('a', RedirectUri::MAX_LENGTH - 20), true];
        yield 'user only' => ['https://user@example.com/cb', false];
        yield 'upper-case https' => ['HTTPS://example.com/cb', true];
        yield 'upper-case loopback' => ['http://LOCALHOST:3000/cb', true];
        yield 'https without a host' => ['https:callback', false];
        yield 'scheme starting with a digit' => ['1cursor://callback', false];
        yield 'upper-case private scheme' => ['Cursor://callback', true];
    }

    #[DataProvider('redirects')]
    public function testRedirectEdges(string $uri, bool $allowed): void
    {
        self::assertSame($allowed, RedirectUri::isAllowed($uri));
    }

    public function testAPrivateSchemeIsShownInLowerCase(): void
    {
        self::assertSame('cursor://anysphere.cursor-mcp', RedirectUri::displayHost('CURSOR://anysphere.cursor-mcp/cb'));
        self::assertSame('https:///cb', RedirectUri::displayHost('https:///cb'));
    }

    public function testOnlyLoopbackRedirectsMatchOnAnyPort(): void
    {
        self::assertFalse(RedirectUri::matches('https://localhost:1/cb', 'https://localhost:2/cb'));
        self::assertFalse(RedirectUri::matches('http://localhost:1/cb', 'https://localhost:1/cb'));
        self::assertFalse(RedirectUri::matches('https://localhost/cb', 'http://localhost:2/cb'));
    }

    /**
     * @param list<ApiScope> $scopes
     */
    private function code(string $hash, array $scopes): AuthorizationCode
    {
        return new AuthorizationCode($hash, 'client', 'user', 'https://client.example.com/cb', self::CHALLENGE, $scopes, 'https://foresi.example.com/mcp', new \DateTimeImmutable('2026-11-18 10:00:00'));
    }

    /**
     * @param list<string> $redirectUris
     */
    private function client(string $clientId, string $name = 'Name', array $redirectUris = ['https://a.example.com/cb']): OAuthClient
    {
        return new OAuthClient($clientId, OAuthClientKind::Registered, $name, $redirectUris, new \DateTimeImmutable());
    }
}
