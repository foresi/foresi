<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mcp;

use App\Shared\Application\Api\IdempotencyKeyInFlight;
use App\Shared\Application\Api\IdempotencyKeyReused;
use App\Shared\Application\Api\IdempotencyKeys;
use App\Shared\Application\Api\SavedAnswer;

/**
 * Idempotency keys in memory, by operation and key, with no token behind them.
 */
final class FakeIdempotencyKeys implements IdempotencyKeys
{
    /** @var array<string, array{string, ?SavedAnswer}> fingerprint and answer, null while in flight */
    public array $keys = [];

    public function claim(string $operation, string $key, string $fingerprint): ?SavedAnswer
    {
        $id = $operation.' '.$key;
        if (!isset($this->keys[$id])) {
            $this->keys[$id] = [$fingerprint, null];

            return null;
        }
        [$known, $answer] = $this->keys[$id];
        if ($known !== $fingerprint) {
            throw new IdempotencyKeyReused();
        }

        return $answer ?? throw new IdempotencyKeyInFlight();
    }

    public function complete(string $operation, string $key, SavedAnswer $answer): void
    {
        $this->keys[$operation.' '.$key][1] = $answer;
    }

    public function release(string $operation, string $key): void
    {
        unset($this->keys[$operation.' '.$key]);
    }
}
