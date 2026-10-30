<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Party;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\VatId;

/**
 * Runs the public VAT checks for a party and stores the outcomes on it with
 * the time of the check, and the bank accounts the register publishes. A
 * register that cannot be reached is recorded as unavailable; it never stops
 * the caller from saving the party. Fetching and storing are separate steps so
 * a caller can ask the registers before it opens a transaction. The caller
 * flushes.
 */
final readonly class RefreshRegistryChecks
{
    public function __construct(private VatRegistry $vies, private VatPayerReliability $reliability)
    {
    }

    public function fetch(VatId $vatId): RegistryOutcome
    {
        $vies = $this->vies->checkVatId($vatId);
        // The unreliable payer register is Czech: other countries have no such check.
        if ('CZ' !== $vatId->country()) {
            return new RegistryOutcome($vies, null, []);
        }
        $result = $this->reliability->check($vatId);

        return new RegistryOutcome($vies, $result->check, $result->publishedBankAccounts);
    }

    public function store(Party $party, RegistryOutcome $outcome): void
    {
        $party->recordRegistryCheck($outcome->vies);
        if ($outcome->reliability instanceof \App\Party\Domain\RegistryCheck) {
            $party->recordRegistryCheck($outcome->reliability);
            // An unreachable register says nothing about the accounts: keep what it published before.
            if (RegistryResult::Unavailable !== $outcome->reliability->result) {
                $party->recordPublishedBankAccounts($outcome->publishedBankAccounts);
            }
        }
    }

    public function refresh(Party $party): void
    {
        $vatId = $party->vatId();
        if ($vatId instanceof VatId) {
            $this->store($party, $this->fetch($vatId));
        }
    }
}
