<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Api;

/**
 * The idempotency keys of the token the request signed in with (ADR 0032): a
 * key is claimed before a write runs, and completed with its answer or
 * released after. A key belongs to one organisation, one token and one
 * operation, and is kept for a day.
 */
interface IdempotencyKeys
{
    /**
     * @param string $operation   the surface and the operation, such as "rest:api_v1_contacts.create"
     * @param string $fingerprint the SHA-256 of the input, hex
     *
     * @return SavedAnswer|null the answer of an earlier request with this key and input, or null when the key is now claimed for this one
     *
     * @throws IdempotencyKeyReused   the key was used with another input
     * @throws IdempotencyKeyInFlight a request with the key is still running
     */
    public function claim(string $operation, string $key, string $fingerprint): ?SavedAnswer;

    public function complete(string $operation, string $key, SavedAnswer $answer): void;

    /**
     * Lets the key go, so it can be sent again: the request failed before it ran, or on the server.
     */
    public function release(string $operation, string $key): void;
}
