<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Party\Domain\LegalEntity;

/**
 * The pairing switches of a firm.
 */
interface FirmPairingRules
{
    public function for(LegalEntity $firm): PairingRules;
}
