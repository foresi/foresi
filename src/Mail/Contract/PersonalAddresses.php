<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

use Foresi\Shared\Contract\Refused;

/**
 * The signed-in person's own addresses of a purpose that belongs to people, for
 * a module's own settings screen: the same rules, refusals and audit as the
 * address calls of the API and MCP. The staff only; someone else's address is
 * as unknown as one that does not exist. Never log an address.
 */
interface PersonalAddresses
{
    /**
     * @return list<PersonalAddress> oldest first, revoked ones too
     *
     * @throws Refused
     */
    public function mine(string $purpose): array;

    /**
     * @throws Refused
     */
    public function create(string $purpose, string $label): IssuedPersonalAddress;

    /**
     * @throws Refused
     */
    public function rename(string $addressId, string $label): PersonalAddress;

    /**
     * Revokes the address and issues a new one with the same purpose and label, in one transaction.
     *
     * @throws Refused
     */
    public function replace(string $addressId): IssuedPersonalAddress;

    /**
     * @throws Refused
     */
    public function revoke(string $addressId): PersonalAddress;
}
