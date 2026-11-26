<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * Whose an inbound address is, for a purpose's accept(), which runs with nobody
 * signed in.
 */
interface AddressOwners
{
    /**
     * The user id of the person the address belongs to; null for an unknown or
     * revoked address and for one that belongs to nobody, such as a bank feed.
     */
    public function ownerOf(string $addressId): ?string;
}
