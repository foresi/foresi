<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyFirmSettings;

/**
 * The per-firm values of the contacts. add() and remove()
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
