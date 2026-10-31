<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Message;

use App\Tenancy\Application\TenantScopedMessage;

/** Writes one chunk of staged rows into the target. Safe to handle twice. */
final readonly class RunImportChunk implements TenantScopedMessage
{
    public function __construct(
        public int $importId,
        public int $chunk,
    ) {
    }
}
