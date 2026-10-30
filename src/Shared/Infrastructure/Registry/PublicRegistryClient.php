<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Registry;

use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one way the application talks to public registers (ARES, VIES, ADIS):
 * a 5 second timeout, an identifying User-Agent without personal data, and a
 * limit on outgoing calls. Registries are best-effort: any failure is logged
 * as a warning and comes back as null, never as an exception.
 */
final readonly class PublicRegistryClient
{
    public const string USER_AGENT = 'Foresi (+https://foresi.app)';
    public const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $http,
        private RateLimiterFactory $registryOutgoingLimiter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $options Symfony HttpClient options (json, body, headers, ...)
     *
     * @return array{status: int, body: string}|null null when the register could not be reached
     */
    public function request(string $registry, string $method, string $url, array $options = []): ?array
    {
        if (!$this->registryOutgoingLimiter->create($registry)->consume()->isAccepted()) {
            $this->logger->warning('Public registry call skipped: outgoing rate limit reached.', ['registry' => $registry]);

            return null;
        }

        $headers = $options['headers'] ?? [];
        \assert(\is_array($headers));
        $options['headers'] = ['User-Agent' => self::USER_AGENT] + $headers;
        $options['timeout'] = self::TIMEOUT_SECONDS;
        $options['max_duration'] = self::TIMEOUT_SECONDS;

        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            if ($status >= 500) {
                $this->logger->warning('Public registry answered with a server error.', ['registry' => $registry, 'status' => $status]);
            }

            return ['status' => $status, 'body' => $response->getContent(false)];
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Public registry is unreachable.', ['registry' => $registry, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Like request(), for JSON registers: returns the decoded body of a 2xx answer.
     *
     * @param array<string, mixed> $options
     *
     * @return array{status: int, data: array<mixed>}|null null when unreachable, a server error or not JSON
     */
    public function requestJson(string $registry, string $method, string $url, array $options = []): ?array
    {
        $options['headers'] = ['Accept' => 'application/json'] + (\is_array($options['headers'] ?? null) ? $options['headers'] : []);
        $answer = $this->request($registry, $method, $url, $options);
        if (null === $answer) {
            return null;
        }
        if ($answer['status'] >= 500) {
            return null;
        }

        try {
            $data = json_decode($answer['body'], true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('Public registry answered with something that is not JSON.', ['registry' => $registry, 'status' => $answer['status']]);

            return null;
        }

        return \is_array($data) ? ['status' => $answer['status'], 'data' => $data] : null;
    }
}
