<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\IssuedAddress;
use Foresi\Mail\Contract\OwnAddress;
use Foresi\Mail\Contract\OwnAddresses;

/**
 * The signed-in person's own addresses for the screens of other modules: the
 * use cases of the REST and MCP calls, AddressWrites and AddressReads, behind
 * the contract.
 */
final readonly class PersonalAddresses implements OwnAddresses
{
    public function __construct(
        private AddressReads $reads,
        private AddressWrites $writes,
        private AddressIssuer $issuer,
    ) {
    }

    public function mine(string $purpose): ?OwnAddress
    {
        foreach (array_reverse($this->reads->mine($purpose)) as $address) {
            if (null === $address->revokedAt) {
                return $this->own($address);
            }
        }

        return null;
    }

    public function create(string $purpose, string $label): IssuedAddress
    {
        $issued = $this->writes->create(new CreateAddressInput($purpose, $label));

        return new IssuedAddress($issued->view->id, $issued->address);
    }

    public function rename(string $addressId, string $label): OwnAddress
    {
        return $this->own($this->writes->rename(new RenameAddressInput($addressId, $label)));
    }

    public function replace(string $addressId): IssuedAddress
    {
        $issued = $this->writes->replace(new ReplaceAddressInput($addressId));

        return new IssuedAddress($issued->view->id, $issued->address);
    }

    public function revoke(string $addressId): void
    {
        $this->writes->revoke(new RevokeAddressInput($addressId));
    }

    public function reveal(string $addressId): ?string
    {
        foreach ($this->reads->mine() as $address) {
            if ($address->id === $addressId) {
                return null === $address->revokedAt ? $this->issuer->reveal($addressId) : null;
            }
        }

        throw MailRefused::notFound();
    }

    private function own(AddressView $address): OwnAddress
    {
        return new OwnAddress($address->id, $address->purpose, $address->label, $address->maskedAddress, $address->createdAt, $address->lastReceivedAt, $address->droppedToday);
    }
}
