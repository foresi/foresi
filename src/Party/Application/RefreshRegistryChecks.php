<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Party;
use App\Party\Domain\VatId;

/**
 * Runs the public VAT checks for a party and stores the outcomes on it with
 * the time of the check. A register that cannot be reached is recorded as
 * unavailable; it never stops the caller from saving the party. The caller
 * flushes.
 */
final readonly class RefreshRegistryChecks
{
    public function __construct(private VatRegistry $vies, private VatPayerReliability $reliability)
    {
    }

    public function refresh(Party $party): void
    {
        $vatId = $party->vatId();
        if (!$vatId instanceof VatId) {
            return;
        }

        $party->recordRegistryCheck($this->vies->checkVatId($vatId));
        // The unreliable payer register is Czech: other countries have no such check.
        if ('CZ' === $vatId->country()) {
            $party->recordRegistryCheck($this->reliability->check($vatId)->check);
        }
    }
}
