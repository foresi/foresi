<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Api;

/**
 * The idempotency key was sent before with another input: REST 422 and MCP
 * idempotency_key_reused. A new request needs a new key.
 */
final class IdempotencyKeyReused extends \DomainException
{
    public const string CODE = 'idempotency_key_reused';

    public function __construct()
    {
        parent::__construct('This Idempotency-Key was used with another request. Send a new key for a new request.');
    }
}
