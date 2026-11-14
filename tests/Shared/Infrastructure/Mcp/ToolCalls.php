<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mcp;

use App\Shared\Application\Api\ApiClient;
use App\Shared\Infrastructure\Api\Idempotency;
use App\Shared\Infrastructure\Api\WriteBudget;
use App\Shared\Infrastructure\Mcp\ReadToolCall;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The call path of the tools with doubles: a token that has a write budget of
 * its own, and idempotency keys in memory.
 */
final class ToolCalls
{
    public static function create(
        FakeCaller $caller = new FakeCaller(),
        FakeScopes $scopes = new FakeScopes(),
        RecordingLogger $logger = new RecordingLogger(),
        FakeIdempotencyKeys $keys = new FakeIdempotencyKeys(),
        int $writes = 120,
    ): ReadToolCall {
        $limiter = new RateLimiterFactory(['id' => 'writes', 'policy' => 'fixed_window', 'limit' => $writes, 'interval' => '10 minutes'], new InMemoryStorage());
        $client = new class implements ApiClient {
            public function tokenId(): string
            {
                return '019a0000-0000-7000-8000-0000000000aa';
            }
        };

        return new ReadToolCall($caller, $scopes, $logger, new WriteBudget($limiter, $client), new Idempotency($keys));
    }
}
