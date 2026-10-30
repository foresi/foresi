<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;

/**
 * Stores a new party together with its billing address and first e-mail. It
 * flushes, so call it inside TransactionalWork (CreateParty does).
 */
interface Parties
{
    /**
     * @param list<PartyEmail> $emails
     */
    public function register(Party $party, PartyAddress $billing, array $emails): void;

    /**
     * Stores an e-mail of a stored party; the surrounding unit of work flushes.
     */
    public function addEmail(PartyEmail $email): void;

    /**
     * Removes an e-mail of a stored party; the surrounding unit of work flushes.
     */
    public function removeEmail(PartyEmail $email): void;
}
