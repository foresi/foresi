<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * A firm's pairing switches: whether verified movements pay invoices by
 * themselves, and whether a small difference in CZK is settled on the way.
 */
final readonly class PairingRules
{
    public function __construct(public bool $autoPairing, public bool $smallDifference)
    {
    }
}
