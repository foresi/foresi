<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The client of webhooks.http_client: no redirects and 15 s, wrapped so that a
 * private address is refused on the resolved and on the connected IP.
 */
final class WebhookHttpClientFactory
{
    public static function create(bool $allowPrivateHosts): HttpClientInterface
    {
        $client = HttpClient::create([
            'max_redirects' => 0,
            'timeout' => 15,
            'max_duration' => 15,
            'headers' => [
                'User-Agent' => 'Foresi-Webhooks/1 (+https://foresi.app)',
                'Content-Type' => 'application/json',
            ],
        ]);

        return $allowPrivateHosts ? $client : new NoPrivateNetworkHttpClient($client);
    }
}
