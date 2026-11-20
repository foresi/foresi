<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

/**
 * The pairing switches of the instance.
 */
interface FirmPairingRules
{
    public function get(): PairingRules;
}
