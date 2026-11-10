<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mcp;

/**
 * What a read tool answers: the data, which the client gets as structured
 * content and as JSON text, and a one-line summary of it, such as
 * "3 invoices, newest first".
 */
final readonly class ToolOutput
{
    /**
     * @param array<mixed> $data plain values only, as they are encoded to JSON
     */
    public function __construct(
        public array $data,
        public string $summary,
    ) {
    }
}
