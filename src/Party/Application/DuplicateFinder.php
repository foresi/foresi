<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;

/**
 * Finds parties that share a registration number (compared
 * without spaces and punctuation) or a VAT id with the given ones.
 */
interface DuplicateFinder
{
    /**
     * @param string|null $excludePartyId the party being edited, which is not its own duplicate
     *
     * @return list<DuplicateMatch>
     */
    public function find(?RegistrationNumber $registrationNumber, ?VatId $vatId, bool $includeArchived = true, ?string $excludePartyId = null): array;
}
