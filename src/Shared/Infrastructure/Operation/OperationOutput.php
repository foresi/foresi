<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Operation;

/**
 * What an operation answers: the data, a one-line summary for MCP, and for a
 * created record the path it is read at (REST Location).
 */
final readonly class OperationOutput
{
    /**
     * @param array<mixed> $data plain values only, as they are encoded to JSON
     */
    public function __construct(
        public array $data,
        public string $summary,
        public ?string $location = null,
    ) {
    }
}
