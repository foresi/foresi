<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Domain\OAuth;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Domain\Id;

/**
 * What a person approved on the consent screen, until the client exchanges it
 * for tokens: usable once and for a few minutes, bound to the client, the
 * redirect URI, the PKCE challenge and the resource. Only the SHA-256 of the
 * code is kept. The token of the first exchange is remembered, so a second
 * exchange can revoke it (RFC 6749, 4.1.2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_authorization_code')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_authorization_code_hash', columns: ['code_hash'])]
class AuthorizationCode
{
    public const int LIFETIME_SECONDS = 300;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $scopes;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'api_token_id', type: 'guid', nullable: true)]
    private ?string $apiTokenId = null;

    /**
     * @param array<ApiScope> $scopes
     */
    public function __construct(
        #[ORM\Column(name: 'code_hash', type: 'string', length: 64)]
        private string $codeHash,
        #[ORM\Column(name: 'client_id', type: 'guid')]
        private string $clientId,
        #[ORM\Column(name: 'user_id', type: 'guid')]
        private string $userId,
        #[ORM\Column(name: 'redirect_uri', type: 'string', length: RedirectUri::MAX_LENGTH)]
        private string $redirectUri,
        #[ORM\Column(name: 'code_challenge', type: 'string', length: 128)]
        private string $codeChallenge,
        array $scopes,
        #[ORM\Column(type: 'string', length: 2048)]
        private string $resource,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $codeHash)) {
            throw new \InvalidArgumentException('An authorization code keeps the SHA-256 of the code.');
        }
        if (!Pkce::isChallenge($codeChallenge)) {
            throw new \InvalidArgumentException('An authorization code keeps an S256 code challenge.');
        }
        if ([] === $scopes) {
            throw new \InvalidArgumentException('An authorization code grants at least one scope.');
        }
        $this->id = Id::generate();
        $this->scopes = array_values(array_unique(array_map(static fn (ApiScope $scope): string => $scope->value, $scopes)));
        $this->expiresAt = $createdAt->modify(\sprintf('+%d seconds', self::LIFETIME_SECONDS));
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt instanceof \DateTimeImmutable;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    public function codeChallenge(): string
    {
        return $this->codeChallenge;
    }

    /**
     * @return list<ApiScope>
     */
    public function scopes(): array
    {
        return array_map(ApiScope::from(...), $this->scopes);
    }

    public function resource(): string
    {
        return $this->resource;
    }

    public function apiTokenId(): ?string
    {
        return $this->apiTokenId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
