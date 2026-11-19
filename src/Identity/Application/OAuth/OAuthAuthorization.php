<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\OAuth;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\GuestScopes;
use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\AuthorizationCode;
use Foresi\Identity\Domain\OAuth\Pkce;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Application\Api\ApiResources;
use Psr\Clock\ClockInterface;

/**
 * The authorization endpoint (ADR 0036): checks a request before the consent
 * screen shows it, and turns the person's answer into a redirect back to the
 * client, with an authorization code or with access_denied. A request whose
 * client or redirect URI is not trusted never redirects anywhere. A guest
 * connects too, with only the scopes a guest may hold (ADR 0041).
 */
final readonly class OAuthAuthorization
{
    public function __construct(
        private OAuthClients $clients,
        private OAuthStore $store,
        private ApiResources $resources,
        private AuditActor $actor,
        private UserRepository $users,
        private MembershipDirectory $memberships,
        private ClockInterface $clock,
        private GuestScopes $guestScopes,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param string               $resource the URL of the MCP server, the only resource
     *
     * @throws OAuthRefused
     */
    public function check(array $query, string $resource): AuthorizationRequest
    {
        $client = $this->clients->resolve($this->string($query, 'client_id'));
        $redirectUri = $this->string($query, 'redirect_uri');
        if ('' === $redirectUri || !$client->allowsRedirect($redirectUri)) {
            throw OAuthRefused::toPerson(OAuthRefused::INVALID_REQUEST, 'The redirect_uri is not one the client registered.');
        }
        // From here on the client hears about every error, at its own redirect URI.
        $state = $this->string($query, 'state');
        $state = '' === $state ? null : $state;
        try {
            if ('code' !== $this->string($query, 'response_type')) {
                throw new OAuthRefused(OAuthRefused::UNSUPPORTED_RESPONSE_TYPE, 'Only response_type=code is supported.');
            }
            $challenge = $this->string($query, 'code_challenge');
            if ('S256' !== $this->string($query, 'code_challenge_method') || !Pkce::isChallenge($challenge)) {
                throw new OAuthRefused(OAuthRefused::INVALID_REQUEST, 'PKCE with code_challenge_method=S256 is required.');
            }
            if (!self::sameResource($this->string($query, 'resource'), $resource)) {
                throw new OAuthRefused(OAuthRefused::INVALID_TARGET, 'The resource parameter must be the URL of the MCP server, '.$resource.'.');
            }
            $scopes = $this->requestedScopes($this->string($query, 'scope'));
        } catch (OAuthRefused $refused) {
            throw $refused->at($redirectUri, $state);
        }

        return new AuthorizationRequest($client, $redirectUri, $state, $challenge, $scopes, $resource);
    }

    /**
     * The values of a space-separated scope parameter.
     *
     * @return list<string>
     */
    public static function scopeValues(string $scope): array
    {
        $values = preg_split('/ +/', $scope, -1, \PREG_SPLIT_NO_EMPTY);

        return false === $values ? [] : $values;
    }

    /**
     * The signed-in person approves the request with these scopes. Read access
     * the client asked for is always granted.
     *
     * @param list<ApiScope> $granted
     *
     * @return string where to send the person: the client's redirect URI with the code
     *
     * @throws OAuthRefused    when nothing is granted
     * @throws ApiTokenRefused when the person may not connect a client
     */
    public function approve(AuthorizationRequest $request, array $granted, string $issuer): string
    {
        $userId = $this->connectingPerson();
        $scopes = [];
        foreach ($this->forPerson([...$request->requiredScopes(), ...$this->declared($granted)]) as $scope) {
            $scopes[$scope->value] = $scope;
        }
        if ([] === $scopes) {
            throw OAuthRefused::toPerson(OAuthRefused::INVALID_SCOPE, 'Choose what the client may do.');
        }

        $code = ApiTokenSecret::random();
        $this->store->addCode(new AuthorizationCode(ApiTokenSecret::hash($code), $request->client->id(), $userId, $request->redirectUri, $request->codeChallenge, $scopes, $request->resource, $this->clock->now()));
        $this->store->flush();

        return self::redirect($request->redirectUri, ['code' => $code, 'state' => $request->state, 'iss' => $issuer]);
    }

    public function deny(AuthorizationRequest $request, string $issuer): string
    {
        return self::redirect($request->redirectUri, ['error' => OAuthRefused::ACCESS_DENIED, 'error_description' => 'The person did not allow access.', 'state' => $request->state, 'iss' => $issuer]);
    }

    /**
     * An error the client may hear about, at its redirect URI.
     */
    public static function errorRedirect(string $redirectUri, OAuthRefused $refused, string $issuer): string
    {
        return self::redirect($redirectUri, ['error' => $refused->error, 'error_description' => $refused->description, 'state' => $refused->state, 'iss' => $issuer]);
    }

    /**
     * Whether the person signed in may connect a client: someone of the
     * organisation, a guest included (ADR 0041), with two-factor sign-in, as
     * for a token (ADR 0027).
     *
     * @throws ApiTokenRefused
     */
    public function connectingPerson(): string
    {
        $userId = $this->actor->userId();
        $role = null === $userId ? null : $this->memberships->roleOf($userId);
        if (null === $userId || !$role instanceof Role) {
            throw new ApiTokenRefused(ApiTokenRefused::NOT_STAFF);
        }
        $user = $this->users->findById($userId);
        if (!$user instanceof User || !$user->hasTwoFactor()) {
            throw new ApiTokenRefused(ApiTokenRefused::TWO_FACTOR_REQUIRED);
        }

        return $userId;
    }

    /**
     * Whether the person signed in connects as a guest, who is offered only the scopes a guest may hold.
     */
    public function connectsAsGuest(): bool
    {
        $userId = $this->actor->userId();
        $role = null === $userId ? null : $this->memberships->roleOf($userId);

        return $role instanceof Role && !$role->isStaff();
    }

    /**
     * The scopes the person signed in may give a client: all of them for
     * someone who works for the organisation, those open to guests for a guest.
     *
     * @param list<ApiScope> $scopes
     *
     * @return list<ApiScope>
     */
    public function forPerson(array $scopes): array
    {
        return $this->connectsAsGuest() ? $this->guestScopes->narrow($scopes) : $scopes;
    }

    /**
     * The resource as the client sent it, with or without a trailing slash.
     */
    public static function sameResource(string $sent, string $resource): bool
    {
        return '' !== $sent && rtrim($sent, '/') === rtrim($resource, '/');
    }

    /**
     * The scopes of the request that Foresi knows; read access when it names none.
     *
     * @return list<ApiScope>
     *
     * @throws OAuthRefused
     */
    private function requestedScopes(string $scope): array
    {
        $values = self::scopeValues($scope);
        if ([] === $values) {
            return [ApiScope::read()];
        }
        $known = $this->declared(array_filter(array_map(ApiScope::tryFrom(...), $values)));
        if ([] === $known) {
            throw new OAuthRefused(OAuthRefused::INVALID_SCOPE, 'None of the requested scopes is known.');
        }

        return $known;
    }

    /**
     * @param array<ApiScope> $scopes
     *
     * @return list<ApiScope> those of the scopes whose resource a module declares
     */
    private function declared(array $scopes): array
    {
        $declared = $this->resources->declared();
        $known = [];
        foreach ($scopes as $scope) {
            if (null === $scope->resource() || \in_array($scope->resource(), $declared, true)) {
                $known[$scope->value] = $scope;
            }
        }

        return array_values($known);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function string(array $query, string $key): string
    {
        $value = $query[$key] ?? '';

        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<string, ?string> $parameters
     */
    private static function redirect(string $uri, array $parameters): string
    {
        // A parameter without a value, such as a missing state, is left out.
        $query = http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986);

        return $uri.(str_contains($uri, '?') ? '&' : '?').$query;
    }
}
