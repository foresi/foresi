<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Api;

/**
 * The answer kept under an idempotency key: over REST the status, the headers
 * that describe the body and the body; over MCP the result as JSON, with no status.
 */
final readonly class SavedAnswer
{
    /**
     * @param array<string, string> $headers such as Content-Type and Location
     */
    public function __construct(
        public ?int $status,
        public string $body,
        public array $headers = [],
    ) {
    }
}
