<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Message;

/** Checks one chunk of staged rows without writing anything. Safe to handle twice. */
final readonly class CheckImportChunk
{
    public function __construct(
        public string $importId,
        public int $chunk,
    ) {
    }
}
