<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Domain\OAuth;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Domain\Id;

/**
 * A program that asks people to connect it over OAuth (ADR 0036). Its client id
 * is either the https URL of its metadata document (CIMD), whose name is
 * vouched for by that URL's host, or an id Foresi gave it at registration (DCR),
 * whose name is only what it said. It is always a public client: no secret.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_client')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_client_client_id', columns: ['client_id'])]
class OAuthClient
{
    public const int NAME_MAX_LENGTH = 80;
    public const int CLIENT_ID_MAX_LENGTH = 2048;
    public const int MAX_REDIRECT_URIS = 10;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::NAME_MAX_LENGTH)]
    private string $name;

    /** @var list<string> */
    #[ORM\Column(name: 'redirect_uris', type: 'json', options: ['jsonb' => true])]
    private array $redirectUris;

    #[ORM\Column(name: 'described_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $describedAt;

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        #[ORM\Column(name: 'client_id', type: 'string', length: self::CLIENT_ID_MAX_LENGTH)]
        private string $clientId,
        #[ORM\Column(type: 'string', length: 8, enumType: OAuthClientKind::class)]
        private OAuthClientKind $kind,
        string $name,
        array $redirectUris,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        if ('' === $clientId || \strlen($clientId) > self::CLIENT_ID_MAX_LENGTH) {
            throw new \InvalidArgumentException('An OAuth client has a client id.');
        }
        $this->id = Id::generate();
        $this->describe($name, $redirectUris, $createdAt);
    }

    /**
     * What the client says about itself: from its metadata document, read again
     * when the copy is old, or from its registration.
     *
     * @param list<string> $redirectUris
     */
    public function describe(string $name, array $redirectUris, \DateTimeImmutable $now): void
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ('' === $name) {
            throw new \InvalidArgumentException('An OAuth client has a name.');
        }
        if ([] === $redirectUris || \count($redirectUris) > self::MAX_REDIRECT_URIS) {
            throw new \InvalidArgumentException(\sprintf('An OAuth client has 1 to %d redirect URIs.', self::MAX_REDIRECT_URIS));
        }
        foreach ($redirectUris as $uri) {
            if (!RedirectUri::isAllowed($uri)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not an allowed redirect URI.', $uri));
            }
        }
        $this->name = mb_substr($name, 0, self::NAME_MAX_LENGTH);
        $this->redirectUris = array_values(array_unique($redirectUris));
        $this->describedAt = $now;
    }

    public function allowsRedirect(string $uri): bool
    {
        return array_any($this->redirectUris, static fn (string $registered): bool => RedirectUri::matches($registered, $uri));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function kind(): OAuthClientKind
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function redirectUris(): array
    {
        return $this->redirectUris;
    }

    /**
     * The host that vouches for the name: that of the metadata document; null for a registered client.
     */
    public function verifiedHost(): ?string
    {
        if (OAuthClientKind::Metadata !== $this->kind) {
            return null;
        }
        $host = parse_url($this->clientId, \PHP_URL_HOST);

        return \is_string($host) ? $host : null;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function describedAt(): \DateTimeImmutable
    {
        return $this->describedAt;
    }
}
