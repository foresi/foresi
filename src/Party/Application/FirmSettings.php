<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyFirmSettings;

/**
 * The per-firm values of the contacts of the active tenant. add() and remove()
 * are for a unit of work that flushes.
 */
interface FirmSettings
{
    /**
     * Null also for a party that is not stored yet. A row removed in the
     * running unit of work is still found; add() brings it back.
     */
    public function find(Party $party, LegalEntity $firm): ?PartyFirmSettings;

    public function add(PartyFirmSettings $settings): void;

    public function remove(PartyFirmSettings $settings): void;

    /**
     * The firm's own invoicing e-mails, the shared ones not among them.
     *
     * @return list<PartyEmail>
     */
    public function emails(Party $party, LegalEntity $firm): array;
}
