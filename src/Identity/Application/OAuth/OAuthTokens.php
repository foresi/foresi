<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\OAuth;

use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Application\GuestScopes;
use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\AuthorizationCode;
use Foresi\Identity\Domain\OAuth\Pkce;
use Foresi\Identity\Domain\OAuth\RefreshToken;
use Foresi\Identity\Domain\Role;
use Psr\Clock\ClockInterface;

/**
 * The token endpoint (ADR 0036). An authorization code becomes a connection:
 * an API token row linked to the client, with an access token for an hour and
 * a refresh token for 30 days. A refresh puts a new access token into the same
 * row and replaces the refresh token. A code or a refresh token presented a
 * second time ends the connection, as it may have been stolen. A guest's
 * connection holds and answers only the scopes a guest may hold (ADR 0041).
 */
final readonly class OAuthTokens
{
    public const int ACCESS_LIFETIME_SECONDS = 3600;

    public function __construct(
        private OAuthClients $clients,
        private OAuthStore $store,
        private ApiTokens $tokens,
        private MembershipDirectory $memberships,
        private ClockInterface $clock,
        private GuestScopes $guestScopes,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters the form-encoded body
     *
     * @throws OAuthRefused
     */
    public function grant(array $parameters, string $resource): TokenResponse
    {
        return match ($this->string($parameters, 'grant_type')) {
            'authorization_code' => $this->exchange($parameters),
            'refresh_token' => $this->refresh($parameters, $resource),
            '' => throw new OAuthRefused(OAuthRefused::INVALID_REQUEST, 'The grant_type is missing.'),
            default => throw new OAuthRefused(OAuthRefused::UNSUPPORTED_GRANT_TYPE, 'Only authorization_code and refresh_token are supported.'),
        };
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws OAuthRefused
     */
    private function exchange(array $parameters): TokenResponse
    {
        $client = $this->clients->known($this->string($parameters, 'client_id'));
        $secret = $this->string($parameters, 'code');
        $code = '' === $secret ? null : $this->store->codeByHash(ApiTokenSecret::hash($secret));
        if (!$code instanceof AuthorizationCode || $code->clientId() !== $client->id()) {
            throw $this->invalidGrant('The authorization code is not valid.');
        }
        $now = $this->clock->now();
        if ($code->isUsed()) {
            $this->endConnection($code->apiTokenId());
            throw $this->invalidGrant('The authorization code was used already.');
        }
        if ($code->isExpired($now)) {
            throw $this->invalidGrant('The authorization code has expired.');
        }
        if ($this->string($parameters, 'redirect_uri') !== $code->redirectUri()) {
            throw $this->invalidGrant('The redirect_uri differs from that of the authorization request.');
        }
        if (!Pkce::verifies($this->string($parameters, 'code_verifier'), $code->codeChallenge())) {
            throw $this->invalidGrant('The code_verifier does not match the code_challenge.');
        }
        $this->checkResource($parameters, $code->resource());
        if (!$this->store->claimCode($code->id(), $now)) {
            throw $this->invalidGrant('The authorization code was used already.');
        }
        $scopes = $this->scopesOf($code->userId(), $code->scopes());

        $access = ApiTokenSecret::generateOAuth();
        $token = new ApiToken($code->userId(), $client->name(), $scopes, ApiTokenSecret::hash($access), ApiTokenSecret::lastFour($access), $now, $this->accessExpiry($now), $client->id());
        $this->tokens->add($token);
        $this->tokens->flush();
        $this->store->attachToken($code->id(), $token->id());

        return new TokenResponse($access, self::ACCESS_LIFETIME_SECONDS, $this->newRefreshToken($token->id(), $now), $token->scopes());
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws OAuthRefused
     */
    private function refresh(array $parameters, string $resource): TokenResponse
    {
        $client = $this->clients->known($this->string($parameters, 'client_id'));
        $secret = $this->string($parameters, 'refresh_token');
        $refresh = str_starts_with($secret, RefreshToken::PREFIX) ? $this->store->refreshTokenByHash(ApiTokenSecret::hash($secret)) : null;
        $token = $refresh instanceof RefreshToken ? $this->tokens->find($refresh->apiTokenId()) : null;
        if (!$refresh instanceof RefreshToken || !$token instanceof ApiToken || $token->oauthClientId() !== $client->id()) {
            throw $this->invalidGrant('The refresh token is not valid.');
        }
        $now = $this->clock->now();
        if ($refresh->isUsed()) {
            $this->endConnection($token->id());
            throw $this->invalidGrant('The refresh token was used already; the connection is revoked.');
        }
        if ($token->revokedAt() instanceof \DateTimeImmutable) {
            throw $this->invalidGrant('The connection has been revoked.');
        }
        if ($refresh->isExpired($now)) {
            throw $this->invalidGrant('The refresh token has expired.');
        }
        $this->checkResource($parameters, $resource);
        $scopes = $this->scopesOf($token->userId(), $token->scopes());
        $this->checkNarrowerScope($this->string($parameters, 'scope'), $scopes);
        if (!$this->store->claimRefreshToken($refresh->id(), $now)) {
            $this->endConnection($token->id());
            throw $this->invalidGrant('The refresh token was used already; the connection is revoked.');
        }

        $access = ApiTokenSecret::generateOAuth();
        $this->tokens->rotate($token->id(), ApiTokenSecret::hash($access), ApiTokenSecret::lastFour($access), $this->accessExpiry($now));

        return new TokenResponse($access, self::ACCESS_LIFETIME_SECONDS, $this->newRefreshToken($token->id(), $now), $scopes);
    }

    private function newRefreshToken(string $apiTokenId, \DateTimeImmutable $now): string
    {
        $secret = RefreshToken::PREFIX.ApiTokenSecret::random();
        $this->store->addRefreshToken(new RefreshToken(ApiTokenSecret::hash($secret), $apiTokenId, $now));
        $this->store->flush();

        return $secret;
    }

    private function endConnection(?string $apiTokenId): void
    {
        $token = null === $apiTokenId ? null : $this->tokens->find($apiTokenId);
        if ($token instanceof ApiToken) {
            $token->revoke(null, $this->clock->now());
            $this->tokens->flush();
        }
    }

    private function accessExpiry(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('+%d seconds', self::ACCESS_LIFETIME_SECONDS));
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws OAuthRefused
     */
    private function checkResource(array $parameters, string $resource): void
    {
        $sent = $this->string($parameters, 'resource');
        if ('' !== $sent && !OAuthAuthorization::sameResource($sent, $resource)) {
            throw new OAuthRefused(OAuthRefused::INVALID_TARGET, 'The resource must be the URL of the MCP server, '.$resource.'.');
        }
    }

    /**
     * A refresh may ask for the same scopes or fewer (RFC 6749, 6); the
     * connection keeps its scopes either way, so asking for more is refused.
     *
     * @param list<ApiScope> $held
     *
     * @throws OAuthRefused
     */
    private function checkNarrowerScope(string $scope, array $held): void
    {
        foreach (OAuthAuthorization::scopeValues($scope) as $value) {
            $wanted = ApiScope::tryFrom($value);
            if (!$wanted instanceof ApiScope || !array_any($held, static fn (ApiScope $granted): bool => $granted->grants($wanted))) {
                throw new OAuthRefused(OAuthRefused::INVALID_SCOPE, 'A refresh cannot add scopes.');
            }
        }
    }

    /**
     * The scopes the person may hold now: all granted ones while they work for
     * the organisation, those open to guests while they are a guest.
     *
     * @param list<ApiScope> $granted
     *
     * @return list<ApiScope>
     *
     * @throws OAuthRefused
     */
    private function scopesOf(string $userId, array $granted): array
    {
        $role = $this->memberships->roleOf($userId);
        if (!$role instanceof Role) {
            throw $this->invalidGrant('The person is no longer in the organisation.');
        }
        if ($role->isStaff()) {
            return $granted;
        }
        $scopes = $this->guestScopes->narrow($granted);

        return [] === $scopes ? throw $this->invalidGrant('A guest may hold none of the scopes of this connection.') : $scopes;
    }

    private function invalidGrant(string $description): OAuthRefused
    {
        return new OAuthRefused(OAuthRefused::INVALID_GRANT, $description);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function string(array $parameters, string $key): string
    {
        $value = $parameters[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
