<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\FirmPairing;
use App\Party\Domain\LegalEntity;

/**
 * The stored pairing switches of the firms of the active tenant. Call add()
 * inside TransactionalWork, which flushes.
 */
interface FirmPairings
{
    public function of(LegalEntity $firm): ?FirmPairing;

    public function add(FirmPairing $pairing): void;
}
