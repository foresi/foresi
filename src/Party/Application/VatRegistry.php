<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\RegistryCheck;
use App\Party\Domain\VatId;

/**
 * Checks that a VAT id exists in the EU VIES register. Never throws: an
 * unreachable register gives a check with the result Unavailable.
 */
interface VatRegistry
{
    public function checkVatId(VatId $vatId): RegistryCheck;
}
