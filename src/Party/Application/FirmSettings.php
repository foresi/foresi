<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyFirmSettings;

/**
 * The per-firm settings of the contacts of the active tenant. add() is for a
 * unit of work that flushes.
 */
interface FirmSettings
{
    /**
     * Null also for a party that is not stored yet.
     */
    public function find(Party $party, LegalEntity $firm): ?PartyFirmSettings;

    public function add(PartyFirmSettings $settings): void;
}
