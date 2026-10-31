<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Message;

use App\Tenancy\Application\TenantScopedMessage;

/** Reads the uploaded file of an import, stages its rows and proposes the mapping. Safe to handle twice. */
final readonly class ReadImportFile implements TenantScopedMessage
{
    public function __construct(
        public string $importId,
    ) {
    }
}
