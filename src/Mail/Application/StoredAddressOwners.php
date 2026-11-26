<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Contract\AddressOwners;
use Foresi\Mail\Domain\InboundAddress;

final readonly class StoredAddressOwners implements AddressOwners
{
    public function __construct(private InboundAddressStore $store)
    {
    }

    public function ownerOf(string $addressId): ?string
    {
        $address = $this->store->get($addressId);

        return $address instanceof InboundAddress && !$address->isRevoked() ? $address->ownerUserId() : null;
    }
}
