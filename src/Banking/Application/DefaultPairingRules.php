<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Party\Domain\LegalEntity;

/**
 * The switches every firm starts with: automatic pairing on, small-difference
 * settlement off. Stands in until the firm's own choice is stored.
 */
final readonly class DefaultPairingRules implements FirmPairingRules
{
    public function for(LegalEntity $firm): PairingRules
    {
        return new PairingRules(autoPairing: true, smallDifference: false);
    }
}
