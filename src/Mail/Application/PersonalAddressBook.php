<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Contract\IssuedPersonalAddress;
use Foresi\Mail\Contract\PersonalAddress;
use Foresi\Mail\Contract\PersonalAddresses;

/**
 * The person's own addresses for other modules' screens, through the use cases
 * behind the API and MCP, so the rules stay in one place.
 */
final readonly class PersonalAddressBook implements PersonalAddresses
{
    public function __construct(
        private AddressReads $reads,
        private AddressWrites $writes,
    ) {
    }

    public function mine(string $purpose): array
    {
        return array_map($this->view(...), $this->reads->mine($purpose));
    }

    public function create(string $purpose, string $label): IssuedPersonalAddress
    {
        return $this->issued($this->writes->create(new CreateAddressInput($purpose, $label)));
    }

    public function rename(string $addressId, string $label): PersonalAddress
    {
        return $this->view($this->writes->rename(new RenameAddressInput($addressId, $label)));
    }

    public function replace(string $addressId): IssuedPersonalAddress
    {
        return $this->issued($this->writes->replace(new ReplaceAddressInput($addressId)));
    }

    public function revoke(string $addressId): PersonalAddress
    {
        return $this->view($this->writes->revoke(new RevokeAddressInput($addressId)));
    }

    private function issued(IssuedMailAddress $issued): IssuedPersonalAddress
    {
        return new IssuedPersonalAddress($this->view($issued->view), $issued->address);
    }

    private function view(AddressView $address): PersonalAddress
    {
        return new PersonalAddress($address->id, $address->purpose, $address->label, $address->maskedAddress, $address->createdAt, $address->revokedAt, $address->droppedToday);
    }
}
