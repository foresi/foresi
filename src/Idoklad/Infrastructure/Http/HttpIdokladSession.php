<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Http;

use App\Idoklad\Application\IdokladAccessDenied;
use App\Idoklad\Application\IdokladFailure;
use App\Idoklad\Application\IdokladSession;
use App\Idoklad\Application\IdokladTariffWithoutApi;
use App\Idoklad\Domain\ReadOnlyPaths;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The iDoklad API v3 with a client credentials token. Every request is a GET
 * of a read-only path. A 401 fetches a new token once; a 429 waits as
 * Retry-After says, or backs off, and tries again (the API allows 200
 * requests a minute). Every request sent is counted against the monthly quota.
 */
final class HttpIdokladSession implements IdokladSession
{
    public const string TOKEN_URL = 'https://identity.idoklad.cz/server/connect/token';
    public const string API_URL = 'https://api.idoklad.cz/v3';
    public const string SCOPE = 'idoklad_api';
    public const int PAGE_SIZE = 200;
    public const int ATTEMPTS = 5;
    public const int MAX_WAIT_SECONDS = 60;
    private const float TIMEOUT_SECONDS = 30.0;

    private ?string $token = null;
    private \DateTimeImmutable $tokenExpires;
    private int $requests = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ClockInterface $clock,
        private readonly string $clientId,
        #[\SensitiveParameter]
        private readonly string $clientSecret,
    ) {
        $this->tokenExpires = $clock->now();
    }

    public function get(string $path, array $query = []): mixed
    {
        ReadOnlyPaths::check('GET', $path);
        $renewed = false;
        for ($attempt = 1;; ++$attempt) {
            $token = $this->token();
            ++$this->requests;
            try {
                $response = $this->http->request('GET', self::API_URL.$path, [
                    'query' => $query,
                    'auth_bearer' => $token,
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => self::TIMEOUT_SECONDS,
                ]);
                $status = $response->getStatusCode();
            } catch (ExceptionInterface) {
                throw new IdokladFailure('iDoklad cannot be reached.');
            }
            if (401 === $status && !$renewed) {
                $renewed = true;
                $this->token = null;
                continue;
            }
            if (429 === $status && $attempt < self::ATTEMPTS) {
                $this->clock->sleep($this->wait($response, $attempt));
                continue;
            }

            return $this->data($response, $status);
        }
    }

    public function pages(string $path, array $query = []): iterable
    {
        $page = 1;
        do {
            $data = $this->get($path, ['page' => $page, 'pagesize' => self::PAGE_SIZE] + $query);
            $items = \is_array($data) ? ($data['Items'] ?? null) : null;
            $pages = \is_array($data) ? ($data['TotalPages'] ?? null) : null;
            if (!\is_array($items) || !array_is_list($items) || $items !== array_filter($items, \is_array(...)) || !\is_int($pages)) {
                throw new IdokladFailure('iDoklad returned a list in an unknown shape.');
            }
            /* @var list<array<mixed>> $items */
            yield $page => $items;
            ++$page;
        } while ($page <= $pages);
    }

    public function requests(): int
    {
        return $this->requests;
    }

    private function data(ResponseInterface $response, int $status): mixed
    {
        if (401 === $status) {
            throw new IdokladAccessDenied('iDoklad refused the access token of these credentials.');
        }
        if (402 === $status) {
            throw new IdokladTariffWithoutApi('The iDoklad tariff of this account has no API access, or it expired.');
        }
        if (429 === $status) {
            throw new IdokladFailure('iDoklad refuses more requests for now; try again in a few minutes.');
        }
        if (200 !== $status) {
            throw new IdokladFailure(\sprintf('iDoklad answered with status %d.', $status));
        }
        try {
            $answer = $response->toArray();
        } catch (ExceptionInterface) {
            throw new IdokladFailure('iDoklad gave an answer that is not JSON.');
        }
        if (true !== ($answer['IsSuccess'] ?? null)) {
            throw new IdokladFailure('iDoklad did not succeed with this request.');
        }

        return $answer['Data'] ?? null;
    }

    /**
     * Seconds to wait before the next attempt: what Retry-After says, or 5, 10, 20, 40.
     */
    private function wait(ResponseInterface $response, int $attempt): int
    {
        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? '';
        $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : 5 << ($attempt - 1);

        return max(1, min(self::MAX_WAIT_SECONDS, $seconds));
    }

    private function token(): string
    {
        $now = $this->clock->now();
        if (null !== $this->token && $now < $this->tokenExpires) {
            return $this->token;
        }
        try {
            $response = $this->http->request('POST', self::TOKEN_URL, [
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => self::SCOPE,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            if (400 === $status || 401 === $status) {
                throw new IdokladAccessDenied('iDoklad refused the client id or the client secret.');
            }
            $answer = 200 === $status ? $response->toArray() : [];
        } catch (ExceptionInterface) {
            throw new IdokladFailure('iDoklad cannot be reached.');
        }
        $token = $answer['access_token'] ?? null;
        $expiresIn = $answer['expires_in'] ?? null;
        if (!\is_string($token) || '' === $token || !\is_int($expiresIn)) {
            throw new IdokladFailure('iDoklad gave no access token.');
        }
        $this->token = $token;
        // A minute early, so a token never runs out between the check and the request.
        $this->tokenExpires = $now->modify(\sprintf('+%d seconds', $expiresIn - 60));

        return $token;
    }
}
