<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Api;

use Foresi\Shared\Application\Api\ApiClient;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The second budget of a token, for its writes only: 120 in 10 minutes over
 * REST and MCP together (the api_token_write limiter, ADR 0032), on top of the
 * budget every request spends. A deterministic cap under whatever an agent
 * decides to do.
 */
final readonly class WriteBudget
{
    public function __construct(private RateLimiterFactoryInterface $apiTokenWriteLimiter, private ApiClient $client)
    {
    }

    /**
     * Spends one write of the token's budget.
     *
     * @return RateLimit|null null without a token, which has no budget to spend
     */
    public function spend(): ?RateLimit
    {
        $tokenId = $this->client->tokenId();

        return null === $tokenId ? null : $this->apiTokenWriteLimiter->create($tokenId)->consume();
    }

    public static function secondsUntil(RateLimit $limit): int
    {
        return max(0, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
