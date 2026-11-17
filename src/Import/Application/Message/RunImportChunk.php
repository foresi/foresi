<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Message;

/** Writes one chunk of staged rows into the target. Safe to handle twice. */
final readonly class RunImportChunk
{
    public function __construct(
        public string $importId,
        public int $chunk,
    ) {
    }
}
