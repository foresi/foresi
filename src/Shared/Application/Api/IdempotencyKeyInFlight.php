<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Api;

/**
 * A request with the same idempotency key is still running: REST 409 and MCP
 * idempotency_key_in_flight. Retry after a moment with the same key.
 */
final class IdempotencyKeyInFlight extends \DomainException
{
    public const string CODE = 'idempotency_key_in_flight';

    public function __construct()
    {
        parent::__construct('A request with this Idempotency-Key is still running. Retry in a moment with the same key.');
    }
}
