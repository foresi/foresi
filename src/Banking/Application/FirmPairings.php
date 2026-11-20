<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Domain\FirmPairing;

/**
 * The stored pairing switches of the instance. Call add() inside
 * TransactionalWork, which flushes.
 */
interface FirmPairings
{
    public function stored(): ?FirmPairing;

    public function add(FirmPairing $pairing): void;
}
