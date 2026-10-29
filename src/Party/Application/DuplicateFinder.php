<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;

/**
 * Finds parties of the active tenant that share a registration number (compared
 * without spaces and punctuation) or a VAT id with the given ones.
 */
interface DuplicateFinder
{
    /**
     * @param int|null $excludePartyId the party being edited, which is not its own duplicate
     *
     * @return list<DuplicateMatch>
     */
    public function find(?RegistrationNumber $registrationNumber, ?VatId $vatId, bool $includeArchived = true, ?int $excludePartyId = null): array;
}
