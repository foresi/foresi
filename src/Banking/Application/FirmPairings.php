<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Domain\FirmPairing;
use Foresi\Party\Domain\LegalEntity;

/**
 * The stored pairing switches of the firms. Call add() inside
 * TransactionalWork, which flushes.
 */
interface FirmPairings
{
    public function of(LegalEntity $firm): ?FirmPairing;

    public function add(FirmPairing $pairing): void;
}
