<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\OAuth;

use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Application\OAuth\ClientMetadataDocuments;
use Foresi\Identity\Application\OAuth\OAuthClients;
use Foresi\Identity\Application\OAuth\OAuthRefused;
use Foresi\Identity\Application\OAuth\OAuthTokens;
use Foresi\Identity\Application\OAuth\TokenResponse;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\AuthorizationCode;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\OAuth\Pkce;
use Foresi\Identity\Domain\OAuth\RefreshToken;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\InMemoryApiTokens;
use Foresi\Tests\Identity\Support\InMemoryOAuthStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The token endpoint without HTTP (ADR 0036): a code becomes a connection, a
 * refresh renews it, and a secret presented twice ends it.
 */
final class OAuthTokensTest extends TestCase
{
    private const string RESOURCE = 'https://foresi.example.com/mcp';
    private const string REDIRECT = 'https://client.example.com/callback';
    private const string USER = '019a0000-0000-7000-8000-000000000001';

    private InMemoryOAuthStore $store;
    private InMemoryApiTokens $tokens;
    private MockClock $clock;
    private OAuthClient $client;
    private ?Role $role = Role::Member;
    private string $verifier;

    protected function setUp(): void
    {
        $this->store = new InMemoryOAuthStore();
        $this->tokens = new InMemoryApiTokens();
        $this->clock = new MockClock('2026-11-18 10:00:00');
        $this->client = new OAuthClient('foresi_client_one', OAuthClientKind::Registered, 'Example Client', [self::REDIRECT], $this->clock->now());
        $this->store->addClient($this->client);
        $this->store->addClient(new OAuthClient('foresi_client_two', OAuthClientKind::Registered, 'Other', [self::REDIRECT], $this->clock->now()));
        $this->verifier = str_repeat('v', 43);
    }

    public function testACodeBecomesAConnection(): void
    {
        $secret = $this->code();

        $response = $this->exchange($secret);

        self::assertStringStartsWith(ApiTokenSecret::OAUTH_PREFIX, $response->accessToken);
        self::assertStringStartsWith(RefreshToken::PREFIX, $response->refreshToken);
        self::assertSame(OAuthTokens::ACCESS_LIFETIME_SECONDS, $response->expiresIn);
        self::assertSame(['read', 'contacts:write'], $this->values($response->scopes));
        self::assertCount(1, $this->tokens->tokens);
        $token = $this->tokens->tokens[0];
        self::assertSame(self::USER, $token->userId());
        self::assertSame('Example Client', $token->name());
        self::assertSame($this->client->id(), $token->oauthClientId());
        self::assertSame(ApiTokenSecret::lastFour($response->accessToken), $token->lastFour());
        self::assertEquals(new \DateTimeImmutable('2026-11-18 11:00:00'), $token->expiresAt());
        self::assertSame(1, $this->tokens->flushes);
        self::assertSame([$this->store->codes[0]->id() => $token->id()], $this->store->attached);
        self::assertTrue($this->store->codes[0]->isUsed());
        self::assertCount(1, $this->store->refreshTokens);
        self::assertSame($token->id(), $this->store->refreshTokens[0]->apiTokenId());
    }

    public function testTheGrantTypeIsRequired(): void
    {
        self::assertSame(OAuthRefused::INVALID_REQUEST, $this->refused([])->error);
        self::assertSame(OAuthRefused::UNSUPPORTED_GRANT_TYPE, $this->refused(['grant_type' => 'password'])->error);
    }

    public function testACodeOfAnotherClientIsNotValid(): void
    {
        $secret = $this->code();

        $refused = $this->refused($this->exchangeParameters($secret, ['client_id' => 'foresi_client_two']));

        self::assertSame('The authorization code is not valid.', $refused->description);
        self::assertSame([], $this->tokens->tokens);
    }

    public function testACodeIsBoundToItsRedirectVerifierAndResource(): void
    {
        $secret = $this->code();

        self::assertSame('The redirect_uri differs from that of the authorization request.', $this->refused($this->exchangeParameters($secret, ['redirect_uri' => self::REDIRECT.'/x']))->description);
        self::assertSame('The code_verifier does not match the code_challenge.', $this->refused($this->exchangeParameters($secret, ['code_verifier' => str_repeat('w', 43)]))->description);
        $target = $this->refused($this->exchangeParameters($secret, ['resource' => 'https://other.example.com/mcp']));
        self::assertSame(OAuthRefused::INVALID_TARGET, $target->error);
        self::assertSame('The resource must be the URL of the MCP server, '.self::RESOURCE.'.', $target->description);
        self::assertSame([], $this->tokens->tokens);
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $secret = $this->code();
        $this->clock->sleep(AuthorizationCode::LIFETIME_SECONDS);

        self::assertSame('The authorization code has expired.', $this->refused($this->exchangeParameters($secret))->description);
    }

    public function testACodeUsedTwiceEndsTheConnection(): void
    {
        $secret = $this->code();
        $this->exchange($secret);
        $this->clock->sleep(AuthorizationCode::LIFETIME_SECONDS);

        $refused = $this->refused($this->exchangeParameters($secret));

        self::assertSame(OAuthRefused::INVALID_GRANT, $refused->error);
        self::assertSame('The authorization code was used already.', $refused->description);
        self::assertEquals($this->clock->now(), $this->tokens->tokens[0]->revokedAt());
    }

    public function testACodeClaimedByAnotherExchangeIsRefused(): void
    {
        $secret = $this->code();
        $this->store->lostRace = $this->store->codes[0]->id();

        self::assertSame('The authorization code was used already.', $this->refused($this->exchangeParameters($secret))->description);
        self::assertSame([], $this->tokens->tokens);
    }

    public function testACodeOfSomeoneWhoLeftIsRefused(): void
    {
        $secret = $this->code();
        $this->role = Role::Guest;

        self::assertSame('The person no longer works for the organisation.', $this->refused($this->exchangeParameters($secret))->description);
        $this->role = null;
        self::assertSame('The person no longer works for the organisation.', $this->refused($this->exchangeParameters($this->code()))->description);
        self::assertSame([], $this->tokens->tokens);
    }

    public function testARefreshRenewsTheSameConnection(): void
    {
        $first = $this->exchange($this->code());
        $this->clock->sleep(600);

        $second = $this->grant($this->refreshParameters($first->refreshToken, ['scope' => 'contacts:read read', 'resource' => self::RESOURCE.'/']));

        self::assertNotSame($first->accessToken, $second->accessToken);
        self::assertNotSame($first->refreshToken, $second->refreshToken);
        self::assertSame(['read', 'contacts:write'], $this->values($second->scopes));
        $token = $this->tokens->tokens[0];
        self::assertCount(1, $this->tokens->rotations);
        self::assertSame([$token->id(), ApiTokenSecret::hash($second->accessToken), ApiTokenSecret::lastFour($second->accessToken)], \array_slice($this->tokens->rotations[0], 0, 3));
        self::assertEquals(new \DateTimeImmutable('2026-11-18 11:10:00'), $this->tokens->rotations[0][3]);
        self::assertTrue($this->store->refreshTokens[0]->isUsed());
        self::assertCount(2, $this->store->refreshTokens);
    }

    public function testARefreshTokenOfAnotherClientOrConnectionIsNotValid(): void
    {
        $first = $this->exchange($this->code());

        self::assertSame('The refresh token is not valid.', $this->refused($this->refreshParameters($first->refreshToken, ['client_id' => 'foresi_client_two']))->description);
        self::assertSame('The refresh token is not valid.', $this->refused($this->refreshParameters('not-a-refresh-token'))->description);
        self::assertSame('The refresh token is not valid.', $this->refused($this->refreshParameters(RefreshToken::PREFIX.'unknown'))->description);
        $this->tokens->tokens = [];
        self::assertSame('The refresh token is not valid.', $this->refused($this->refreshParameters($first->refreshToken))->description);
    }

    public function testARefreshTokenUsedTwiceEndsTheConnection(): void
    {
        $first = $this->exchange($this->code());
        $this->grant($this->refreshParameters($first->refreshToken));

        $refused = $this->refused($this->refreshParameters($first->refreshToken));

        self::assertSame('The refresh token was used already; the connection is revoked.', $refused->description);
        self::assertNotNull($this->tokens->tokens[0]->revokedAt());
    }

    public function testARefreshClaimedByAnotherRequestEndsTheConnection(): void
    {
        $first = $this->exchange($this->code());
        $this->store->lostRace = $this->store->refreshTokens[0]->id();

        self::assertSame('The refresh token was used already; the connection is revoked.', $this->refused($this->refreshParameters($first->refreshToken))->description);
        self::assertNotNull($this->tokens->tokens[0]->revokedAt());
        self::assertSame([], $this->tokens->rotations);
    }

    public function testARevokedOrExpiredConnectionIsNotRefreshed(): void
    {
        $first = $this->exchange($this->code());
        $this->clock->sleep(RefreshToken::LIFETIME_DAYS * 86400);
        self::assertSame('The refresh token has expired.', $this->refused($this->refreshParameters($first->refreshToken))->description);

        $this->tokens->tokens[0]->revoke(null, $this->clock->now());
        self::assertSame('The connection has been revoked.', $this->refused($this->refreshParameters($first->refreshToken))->description);
        self::assertSame([], $this->tokens->rotations);
    }

    public function testARefreshKeepsToTheResourceAndTheScopes(): void
    {
        $first = $this->exchange($this->code());

        self::assertSame(OAuthRefused::INVALID_TARGET, $this->refused($this->refreshParameters($first->refreshToken, ['resource' => 'https://other.example.com/mcp']))->error);
        self::assertSame('A refresh cannot add scopes.', $this->refused($this->refreshParameters($first->refreshToken, ['scope' => 'read write']))->description);
        self::assertSame('A refresh cannot add scopes.', $this->refused($this->refreshParameters($first->refreshToken, ['scope' => 'bogus!']))->description);
        self::assertFalse($this->store->refreshTokens[0]->isUsed());
    }

    public function testARefreshOfSomeoneWhoLeftIsRefused(): void
    {
        $first = $this->exchange($this->code());
        $this->role = Role::Guest;

        self::assertSame('The person no longer works for the organisation.', $this->refused($this->refreshParameters($first->refreshToken))->description);
        self::assertSame([], $this->tokens->rotations);
    }

    private function code(): string
    {
        $secret = ApiTokenSecret::random();
        $this->store->addCode(new AuthorizationCode(ApiTokenSecret::hash($secret), $this->client->id(), self::USER, self::REDIRECT, Pkce::challenge($this->verifier), [ApiScope::read(), ApiScope::from('contacts:write')], self::RESOURCE, $this->clock->now()));

        return $secret;
    }

    private function exchange(string $secret): TokenResponse
    {
        return $this->grant($this->exchangeParameters($secret));
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function exchangeParameters(string $secret, array $changes = []): array
    {
        return [...['grant_type' => 'authorization_code', 'client_id' => 'foresi_client_one', 'code' => $secret, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $this->verifier, 'resource' => self::RESOURCE], ...$changes];
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function refreshParameters(string $secret, array $changes = []): array
    {
        return [...['grant_type' => 'refresh_token', 'client_id' => 'foresi_client_one', 'refresh_token' => $secret], ...$changes];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function grant(array $parameters): TokenResponse
    {
        return $this->service()->grant($parameters, self::RESOURCE);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function refused(array $parameters): OAuthRefused
    {
        try {
            $this->grant($parameters);
        } catch (OAuthRefused $refused) {
            return $refused;
        }
        self::fail('The grant is refused.');
    }

    /**
     * @param list<ApiScope> $scopes
     *
     * @return list<string>
     */
    private function values(array $scopes): array
    {
        return array_map(static fn (ApiScope $scope): string => $scope->value, $scopes);
    }

    private function service(): OAuthTokens
    {
        $memberships = self::createStub(MembershipDirectory::class);
        $memberships->method('roleOf')->willReturn($this->role);
        $memberships->method('organisationOf')->willReturn(null);

        return new OAuthTokens(new OAuthClients($this->store, self::createStub(ClientMetadataDocuments::class), $this->clock), $this->store, $this->tokens, $memberships, $this->clock);
    }
}
