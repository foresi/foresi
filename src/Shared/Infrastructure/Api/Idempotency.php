<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Api;

use App\Shared\Application\Api\IdempotencyKeys;
use App\Shared\Application\Api\SavedAnswer;

/**
 * What the REST API and the MCP server share of idempotency (ADR 0032): the
 * shape of a key, the fingerprint of an input and which answers are kept. An
 * answer is kept once the write ran, whether it succeeded or a use case refused
 * it; an answer from before the write ran (an input that does not validate, a
 * missing scope, a refused confirmation, the rate limit) or a failure on the
 * server lets the key go, so it can be sent again.
 */
final readonly class Idempotency
{
    public const string HEADER = 'Idempotency-Key';
    public const string ARGUMENT = 'idempotency_key';
    public const string REPLAYED_HEADER = 'Idempotent-Replayed';
    public const string INVALID = 'The idempotency key must be 1 to 255 visible ASCII characters, such as a UUID.';

    /** Refusals made before the write ran: they are not kept under a key. */
    public const array NOT_KEPT = [
        ApiErrors::INVALID_PARAMETER,
        'invalid_argument',
        'bad_request',
        'unauthorized',
        'insufficient_scope',
        'confirmation_required',
        'rate_limited',
        'method_not_allowed',
        'not_acceptable',
        'unsupported_media_type',
        'internal_error',
        'unavailable',
        'service_unavailable',
    ];

    public function __construct(private IdempotencyKeys $keys)
    {
    }

    public static function validKey(string $key): bool
    {
        return 1 === preg_match('/^[\x21-\x7E]{1,255}$/', $key);
    }

    /**
     * The SHA-256 of a value as canonical JSON: the keys of objects sorted, so
     * the same input in another order is the same input.
     */
    public static function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Whether an answer with this status and code is kept under its key.
     */
    public static function keeps(?int $status, ?string $code): bool
    {
        if (null !== $status && $status < 300) {
            return true;
        }
        if (null !== $status && ($status >= 500 || \in_array($status, [400, 401, 429], true))) {
            return false;
        }

        return null === $code || !(\in_array($code, self::NOT_KEPT, true) || str_starts_with($code, 'idempotency_key_'));
    }

    /**
     * @throws \App\Shared\Application\Api\IdempotencyKeyReused
     * @throws \App\Shared\Application\Api\IdempotencyKeyInFlight
     */
    public function claim(string $operation, string $key, string $fingerprint): ?SavedAnswer
    {
        return $this->keys->claim($operation, $key, $fingerprint);
    }

    public function finish(string $operation, string $key, SavedAnswer $answer, ?string $code): void
    {
        if (self::keeps($answer->status, $code)) {
            $this->keys->complete($operation, $key, $answer);
        } else {
            $this->keys->release($operation, $key);
        }
    }

    public function release(string $operation, string $key): void
    {
        $this->keys->release($operation, $key);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
