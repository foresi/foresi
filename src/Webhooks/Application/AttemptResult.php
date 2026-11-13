<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * What one delivery attempt came to: the status the receiver answered with, how
 * long it took, and a short error code when there was no answer or it was a
 * redirect (timeout, tls, blocked_address, connection, redirect), or when no
 * request was made because the endpoint is disabled (endpoint_disabled) or its
 * secret cannot be opened (internal). The response body is never part of it.
 */
final readonly class AttemptResult
{
    public const string TIMEOUT = 'timeout';
    public const string TLS = 'tls';
    public const string BLOCKED_ADDRESS = 'blocked_address';
    public const string CONNECTION = 'connection';
    public const string REDIRECT = 'redirect';
    public const string ENDPOINT_DISABLED = 'endpoint_disabled';
    public const string INTERNAL = 'internal';

    public bool $succeeded;
    public bool $gone;

    public function __construct(
        public ?int $statusCode,
        public int $durationMs,
        public ?string $error = null,
    ) {
        $this->succeeded = null !== $statusCode && $statusCode >= 200 && $statusCode < 300;
        $this->gone = 410 === $statusCode;
    }
}
