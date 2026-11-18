<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\OAuth;

use Foresi\Identity\Application\OAuth\ClientMetadataDocuments;
use Foresi\Identity\Application\OAuth\OAuthRefused;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads client ID metadata documents (ADR 0036) without letting a client_id
 * reach inside the network: NoPrivateNetworkHttpClient refuses private,
 * loopback, link-local and reserved addresses, checked on every resolved
 * address and again on the connected one, so DNS rebinding does not help.
 * Only https, no redirects, 5 seconds and 5 kB at most.
 */
final readonly class HttpClientMetadataDocuments implements ClientMetadataDocuments
{
    public const int MAX_BYTES = 5120;
    public const float TIMEOUT_SECONDS = 5.0;

    private HttpClientInterface $client;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = new NoPrivateNetworkHttpClient($client);
    }

    public function fetch(string $url): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw $this->unreadable('it is not an https URL');
        }
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                // A bigger answer stops being read once it passes the limit.
                'on_progress' => static function (int $downloaded, int $size): void {
                    if ($downloaded > self::MAX_BYTES || $size > self::MAX_BYTES) {
                        throw new \LengthException('The client ID metadata document is too large.');
                    }
                },
            ]);
            if (200 !== $response->getStatusCode()) {
                throw $this->unreadable('the answer was not 200 OK');
            }
            $body = $response->getContent();
        } catch (ExceptionInterface|\LengthException $failure) {
            throw $this->unreadable($failure instanceof \LengthException || $failure->getPrevious() instanceof \LengthException ? 'it is larger than 5 kB' : 'it could not be reached');
        }
        if (\strlen($body) > self::MAX_BYTES) {
            throw $this->unreadable('it is larger than 5 kB');
        }
        try {
            $document = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->unreadable('it is not JSON');
        }
        if (!\is_array($document) || array_is_list($document)) {
            throw $this->unreadable('it is not a JSON object');
        }
        // Members with numeric names mean nothing in client metadata.
        $members = [];
        foreach ($document as $name => $value) {
            if (\is_string($name)) {
                $members[$name] = $value;
            }
        }

        return $members;
    }

    private function unreadable(string $why): OAuthRefused
    {
        return OAuthRefused::toPerson(OAuthRefused::INVALID_CLIENT, 'The client ID metadata document could not be read: '.$why.'.');
    }
}
