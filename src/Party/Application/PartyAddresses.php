<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\PartyAddress;

/**
 * Adds and removes addresses of parties. A party has exactly one billing address.
 */
interface PartyAddresses
{
    /**
     * @throws \DomainException when the party already has a billing address
     */
    public function add(PartyAddress $address): void;

    /**
     * Deletes an address that is not the billing one; the surrounding unit of work flushes.
     *
     * @throws \DomainException for the billing address
     */
    public function remove(PartyAddress $address): void;
}
