<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

/**
 * What a VAT amount comes from, such as an issued invoice or a received
 * document: its type, its id and, when the source has one, the API path a
 * caller reads it at.
 */
final readonly class VatSubject
{
    public function __construct(
        public string $type,
        public string $id,
        public ?string $url,
    ) {
    }
}
