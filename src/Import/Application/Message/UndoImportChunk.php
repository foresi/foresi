<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Message;

use App\Tenancy\Application\TenantScopedMessage;

/** Puts back one chunk of what a finished import wrote. Safe to handle twice. */
final readonly class UndoImportChunk implements TenantScopedMessage
{
    public function __construct(
        public int $importId,
        public int $chunk,
    ) {
    }
}
