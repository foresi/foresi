<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

use App\Party\Domain\Address;
use App\Party\Domain\AddressType;

final readonly class ContactAddress
{
    public function __construct(public string $id, public AddressType $type, public string $label, public Address $address)
    {
    }
}
