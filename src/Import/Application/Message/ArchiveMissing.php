<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Message;

use App\Tenancy\Application\TenantScopedMessage;

/** Archives one chunk of the records a full replace did not find in the source. Safe to handle twice. */
final readonly class ArchiveMissing implements TenantScopedMessage
{
    public function __construct(
        public string $importId,
        public int $chunk,
    ) {
    }
}
