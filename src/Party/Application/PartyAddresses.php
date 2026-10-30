<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\PartyAddress;

/**
 * Adds addresses to parties. A party has exactly one billing address.
 */
interface PartyAddresses
{
    /**
     * @throws \DomainException when the party already has a billing address
     */
    public function add(PartyAddress $address): void;
}
