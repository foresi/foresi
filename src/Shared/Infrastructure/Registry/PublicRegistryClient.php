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
 * limit on outgoing calls (per registry host: the limiter is keyed by registry
 * name) and a cap on the size of an answer. Registries are best-effort: any failure is logged
 * as a warning and comes back as null, never as an exception.
 */
final readonly class PublicRegistryClient
{
    public const string USER_AGENT = 'Foresi (+https://foresi.app)';
    public const float TIMEOUT_SECONDS = 5.0;
    public const int MAX_BODY_BYTES = 2097152;

    public function __construct(
        private HttpClientInterface $http,
        private RateLimiterFactory $registryOutgoingLimiter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $options  Symfony HttpClient options (json, body, headers, ...)
     * @param list<int>            $accepted statuses that count as an answer; anything else is a failure
     *
     * @return array{status: int, body: string}|null null when the register could not be reached or answered badly
     */
    public function request(string $registry, string $method, string $url, array $options = [], array $accepted = [200]): ?array
    {
        if (!$this->registryOutgoingLimiter->create($registry)->consume()->isAccepted()) {
            $this->fail($registry, 'outgoing rate limit reached, call skipped');

            return null;
        }

        $headers = $options['headers'] ?? [];
        \assert(\is_array($headers));
        $options['headers'] = ['User-Agent' => self::USER_AGENT] + $headers;
        $options['timeout'] = self::TIMEOUT_SECONDS;
        $options['max_duration'] = self::TIMEOUT_SECONDS;

        // Only the exception class and the registry are logged: the URL carries the registration number.
        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $length = $response->getHeaders(false)['content-length'][0] ?? null;
            if (is_numeric($length) && (int) $length > self::MAX_BODY_BYTES) {
                $response->cancel();

                return $this->tooLarge($registry);
            }

            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (\strlen($body) > self::MAX_BODY_BYTES) {
                    $response->cancel();

                    return $this->tooLarge($registry);
                }
            }
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Public registry is unreachable.', ['registry' => $registry, 'exception' => $e::class]);

            return null;
        }

        if (!\in_array($status, $accepted, true)) {
            $this->logger->warning('Public registry answered with an unexpected status.', ['registry' => $registry, 'status' => $status]);

            return null;
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * Like request(), for JSON registers: returns the decoded body.
     *
     * @param array<string, mixed> $options
     * @param list<int>            $accepted
     *
     * @return array{status: int, data: array<mixed>}|null null when unreachable, not accepted or not JSON
     */
    public function requestJson(string $registry, string $method, string $url, array $options = [], array $accepted = [200]): ?array
    {
        $options['headers'] = ['Accept' => 'application/json'] + (\is_array($options['headers'] ?? null) ? $options['headers'] : []);
        $answer = $this->request($registry, $method, $url, $options, $accepted);
        if (null === $answer) {
            return null;
        }

        try {
            $data = json_decode($answer['body'], true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail($registry, 'answer is not JSON');

            return null;
        }
        if (!\is_array($data)) {
            $this->fail($registry, 'answer is not a JSON object');

            return null;
        }

        return ['status' => $answer['status'], 'data' => $data];
    }

    /**
     * Records that a registry gave no usable answer. Every path that turns a
     * registry problem into Unavailable or null goes through here.
     */
    public function fail(string $registry, string $reason): void
    {
        $this->logger->warning('Public registry gave no usable answer: '.$reason.'.', ['registry' => $registry]);
    }

    private function tooLarge(string $registry): null
    {
        $this->fail($registry, 'answer is larger than '.self::MAX_BODY_BYTES.' bytes');

        return null;
    }
}
