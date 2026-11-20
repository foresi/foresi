<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * The document an actual amount pays: its type, such as "invoice" or
 * "received_document", its id and the API path that reads it. The module's
 * own value, so that its contract needs no other module's.
 */
final readonly class Subject
{
    public function __construct(
        public string $type,
        public string $id,
        public ?string $url,
    ) {
    }
}
