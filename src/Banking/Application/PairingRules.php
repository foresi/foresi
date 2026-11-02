<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * A firm's pairing switches: whether verified movements pay invoices by
 * themselves, and whether a small difference is settled on the way where the
 * firm's country allows it.
 */
final readonly class PairingRules
{
    public function __construct(public bool $autoPairing, public bool $smallDifference)
    {
    }
}
