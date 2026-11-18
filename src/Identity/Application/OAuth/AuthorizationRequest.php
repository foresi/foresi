<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\OAuth;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\OAuth\OAuthClient;

/**
 * A checked request of the authorization endpoint, as the consent screen shows it.
 */
final readonly class AuthorizationRequest
{
    /**
     * @param list<ApiScope> $scopes what the client asked for
     */
    public function __construct(
        public OAuthClient $client,
        public string $redirectUri,
        public ?string $state,
        public string $codeChallenge,
        public array $scopes,
        public string $resource,
    ) {
    }

    /**
     * The scopes that stay ticked: the reads the client asked for, without
     * which it can do nothing.
     *
     * @return list<ApiScope>
     */
    public function requiredScopes(): array
    {
        return array_values(array_filter($this->scopes, static fn (ApiScope $scope): bool => !$scope->writes()));
    }

    /**
     * The parameters that carry the request through the consent form.
     *
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return array_filter([
            'response_type' => 'code',
            'client_id' => $this->client->clientId(),
            'redirect_uri' => $this->redirectUri,
            'state' => $this->state,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => implode(' ', array_map(static fn (ApiScope $scope): string => $scope->value, $this->scopes)),
            'resource' => $this->resource,
        ], static fn (?string $value): bool => null !== $value);
    }
}
