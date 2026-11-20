<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

/**
 * The switches an instance starts with: automatic pairing on, small-difference
 * settlement off. Stands in until the own choice is stored.
 */
final readonly class DefaultPairingRules implements FirmPairingRules
{
    public function get(): PairingRules
    {
        return new PairingRules(autoPairing: true, smallDifference: false);
    }
}
