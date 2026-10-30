<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * A party that shares a registration number or VAT id with the one being
 * entered. Shown as a warning, never as a block.
 */
final readonly class DuplicateMatch
{
    public function __construct(
        public int $partyId,
        public string $displayName,
        public bool $archived,
        public bool $sameRegistrationNumber,
        public bool $sameVatId,
    ) {
    }
}
