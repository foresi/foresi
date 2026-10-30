<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support\Registry;

use App\Shared\Infrastructure\Registry\PublicRegistryClient;
use App\Shared\Infrastructure\Registry\RegistryCache;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Wires registry adapters against a mocked HTTP client, an in-memory cache
 * and a fixed clock. Fixtures are anonymised, synthetic registry answers.
 */
final class RegistryTestKit
{
    public readonly RecordingLogger $logger;
    public readonly MockClock $clock;
    public readonly RegistryCache $cache;
    public readonly PublicRegistryClient $client;
    public readonly ArrayAdapter $pool;

    /** @var list<array{method: string, url: string, body: string, headers: list<string>, timeout: mixed, max_duration: mixed}> */
    public array $requests = [];

    /**
     * @param callable(string, string): MockResponse $responder
     */
    public function __construct(callable $responder, int $limit = 1000)
    {
        $this->logger = new RecordingLogger();
        $this->clock = new MockClock('2026-10-30 10:00:00');
        $this->pool = new ArrayAdapter();
        $this->cache = new RegistryCache($this->pool);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($responder): MockResponse {
            $headers = array_values(array_filter(\is_array($options['headers'] ?? null) ? $options['headers'] : [], \is_string(...)));
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_string($options['body'] ?? null) ? $options['body'] : '',
                'headers' => $headers,
                'timeout' => $options['timeout'] ?? null,
                'max_duration' => $options['max_duration'] ?? null,
            ];

            return $responder($method, $url);
        });
        $limiter = new RateLimiterFactory(['id' => 'registry_outgoing', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage());
        $this->client = new PublicRegistryClient($http, $limiter, $this->logger);
    }

    public static function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__.'/../../Fixtures/Registry/'.$name);
        \assert(\is_string($content));

        return $content;
    }

    public static function json(string $fixture, int $status = 200): MockResponse
    {
        return new MockResponse(self::fixture($fixture), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }
}
