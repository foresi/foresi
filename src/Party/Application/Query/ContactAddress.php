<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\Query;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;

final readonly class ContactAddress
{
    public function __construct(public string $id, public AddressType $type, public string $label, public Address $address)
    {
    }
}
