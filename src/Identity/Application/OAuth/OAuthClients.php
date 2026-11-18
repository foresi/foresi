<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\OAuth;

use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\OAuth\RedirectUri;
use Psr\Clock\ClockInterface;

/**
 * Who an OAuth client is (ADR 0036). A client id that is an https URL is a
 * client ID metadata document: it is read, checked and kept for a day. Any
 * other client id must have been registered (RFC 7591), which only public
 * clients can: no secret is ever issued.
 */
final readonly class OAuthClients
{
    /** How long a metadata document is trusted before it is read again. */
    public const int METADATA_TTL_SECONDS = 86400;
    public const string REGISTERED_PREFIX = 'foresi_client_';

    public function __construct(
        private OAuthStore $store,
        private ClientMetadataDocuments $documents,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OAuthRefused
     */
    public function resolve(string $clientId): OAuthClient
    {
        if ('' === $clientId || \strlen($clientId) > OAuthClient::CLIENT_ID_MAX_LENGTH) {
            throw OAuthRefused::toPerson(OAuthRefused::INVALID_CLIENT, 'The client_id is missing.');
        }
        $known = $this->store->clientByClientId($clientId);
        if (!str_starts_with($clientId, 'https://')) {
            return $known ?? throw OAuthRefused::toPerson(OAuthRefused::INVALID_CLIENT, 'The client is not registered.');
        }

        $now = $this->clock->now();
        if ($known instanceof OAuthClient && $known->describedAt() > $now->modify(\sprintf('-%d seconds', self::METADATA_TTL_SECONDS))) {
            return $known;
        }
        [$name, $redirectUris] = $this->readDocument($clientId);
        if ($known instanceof OAuthClient) {
            $known->describe($name, $redirectUris, $now);
            $this->store->flush();

            return $known;
        }
        $client = new OAuthClient($clientId, OAuthClientKind::Metadata, $name, $redirectUris, $now);
        $this->store->addClient($client);
        $this->store->flush();

        return $client;
    }

    /**
     * A known client without fetching anything: for the token endpoint, which
     * only ever sees clients that went through the consent screen.
     *
     * @throws OAuthRefused
     */
    public function known(string $clientId): OAuthClient
    {
        return ('' === $clientId ? null : $this->store->clientByClientId($clientId))
            ?? throw new OAuthRefused(OAuthRefused::INVALID_CLIENT, 'The client is not known.');
    }

    /**
     * Dynamic client registration (RFC 7591) of a public client.
     *
     * @param array<mixed> $metadata
     *
     * @throws OAuthRefused
     */
    public function register(array $metadata): OAuthClient
    {
        $redirectUris = $this->redirectUris($metadata, OAuthRefused::INVALID_REDIRECT_URI);
        $method = $metadata['token_endpoint_auth_method'] ?? 'none';
        if ('none' !== $method) {
            throw new OAuthRefused(OAuthRefused::INVALID_CLIENT_METADATA, 'Only public clients are registered: token_endpoint_auth_method must be "none".');
        }
        foreach (['grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']] as $field => $allowed) {
            $values = $metadata[$field] ?? $allowed;
            if (!\is_array($values) || array_any($values, static fn (mixed $value): bool => !\in_array($value, $allowed, true))) {
                throw new OAuthRefused(OAuthRefused::INVALID_CLIENT_METADATA, \sprintf('Only %s are supported as %s.', implode(' and ', $allowed), $field));
            }
        }
        $name = $metadata['client_name'] ?? null;
        $name = \is_string($name) && '' !== trim($name) ? $name : RedirectUri::displayHost($redirectUris[0]);

        $client = new OAuthClient(self::REGISTERED_PREFIX.bin2hex(random_bytes(16)), OAuthClientKind::Registered, $name, $redirectUris, $this->clock->now());
        $this->store->addClient($client);
        $this->store->flush();

        return $client;
    }

    /**
     * @return array{string, list<string>} the name and the redirect URIs
     *
     * @throws OAuthRefused
     */
    private function readDocument(string $url): array
    {
        if (!self::isMetadataUrl($url)) {
            throw OAuthRefused::toPerson(OAuthRefused::INVALID_CLIENT, 'The client_id is not a valid client ID metadata document URL.');
        }
        $document = $this->documents->fetch($url);
        if (($document['client_id'] ?? null) !== $url) {
            throw OAuthRefused::toPerson(OAuthRefused::INVALID_CLIENT, 'The client ID metadata document names another client_id.');
        }
        if (isset($document['client_secret']) || ($document['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            throw OAuthRefused::toPerson(OAuthRefused::INVALID_CLIENT, 'Only public clients are supported: token_endpoint_auth_method must be "none".');
        }
        $redirectUris = $this->redirectUris($document, OAuthRefused::INVALID_CLIENT);
        $name = $document['client_name'] ?? null;

        return [\is_string($name) && '' !== trim($name) ? $name : RedirectUri::displayHost($url), $redirectUris];
    }

    /**
     * An https URL with a path, and no fragment, credentials or dot segments.
     */
    public static function isMetadataUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (false === $parts || 'https' !== ($parts['scheme'] ?? null) || '' === ($parts['host'] ?? '') || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $path = $parts['path'] ?? '';
        if ('' === $path || '/' === $path) {
            return false;
        }

        return !array_any(explode('/', $path), static fn (string $segment): bool => '.' === $segment || '..' === $segment);
    }

    /**
     * @param array<mixed> $metadata
     *
     * @return list<string>
     *
     * @throws OAuthRefused
     */
    private function redirectUris(array $metadata, string $error): array
    {
        $uris = $metadata['redirect_uris'] ?? null;
        if (!\is_array($uris) || [] === $uris || \count($uris) > OAuthClient::MAX_REDIRECT_URIS) {
            throw new OAuthRefused($error, \sprintf('redirect_uris must list 1 to %d URIs.', OAuthClient::MAX_REDIRECT_URIS));
        }
        $valid = [];
        foreach ($uris as $uri) {
            if (!\is_string($uri) || !RedirectUri::isAllowed($uri)) {
                throw new OAuthRefused($error, 'Each redirect URI must be https, a loopback http URL or a private-use scheme, without a fragment.');
            }
            $valid[] = $uri;
        }

        return $valid;
    }
}
