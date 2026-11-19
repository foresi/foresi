<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Mcp;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Api\ApiClient;
use Foresi\Shared\Infrastructure\Api\Idempotency;
use Foresi\Shared\Infrastructure\Api\WriteBudget;
use Foresi\Shared\Infrastructure\Mcp\ReadToolCall;
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
        bool $staff = true,
    ): ReadToolCall {
        $limiter = new RateLimiterFactory(['id' => 'writes', 'policy' => 'fixed_window', 'limit' => $writes, 'interval' => '10 minutes'], new InMemoryStorage());
        $client = new class implements ApiClient {
            public function tokenId(): string
            {
                return '019a0000-0000-7000-8000-0000000000aa';
            }
        };

        return new ReadToolCall($caller, $scopes, $logger, new WriteBudget($limiter, $client), new Idempotency($keys), self::role($staff));
    }

    public static function role(bool $staff): ActorRole
    {
        return new readonly class($staff) implements ActorRole {
            public function __construct(private bool $staff)
            {
            }

            public function userId(): string
            {
                return '019a0000-0000-7000-8000-0000000000bb';
            }

            public function isOwner(): bool
            {
                return false;
            }

            public function isStaff(): bool
            {
                return $this->staff;
            }

            public function isStaffMember(string $userId): bool
            {
                return $this->staff;
            }
        };
    }
}
