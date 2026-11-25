<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\Event\ContactUpdated;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Countries;

/**
 * The addresses of a contact, as its Addresses tab changes them. A contact has
 * exactly one billing address: it is changed here or in the contact's form,
 * never added or deleted, and no other address becomes one.
 */
final readonly class ManagePartyAddresses
{
    public const int MAX_LABEL = 100;

    public function __construct(
        private PartyAddresses $addresses,
        private PartyDetails $details,
        private TransactionalWork $transaction,
        private PartyAccess $access,
        private EventRecorder $events,
    ) {
    }

    /**
     * @param string $type delivery, postal or other
     *
     * @throws PartyNotAllowed    for a guest
     * @throws ContactPartRefused with every field that is wrong
     */
    public function add(Party $party, string $type, string $street, string $city, string $postalCode, string $countryCode, string $label): PartyAddress
    {
        $this->access->denyUnlessMayChange();
        $kind = AddressType::tryFrom($type);
        $reasons = $this->check($street, $city, $postalCode, $countryCode, $label);
        if (!$kind instanceof AddressType || AddressType::Billing === $kind) {
            $reasons['type'] = ContactPartRefused::ADDRESS_TYPE;
        }
        if ([] !== $reasons || !$kind instanceof AddressType) {
            throw new ContactPartRefused($reasons);
        }
        $address = new PartyAddress($party, $kind, $this->address($street, $city, $postalCode, $countryCode), $label);
        $this->transaction->run(function () use ($party, $address): void {
            $this->addresses->add($address);
            $this->changed($party);
        });

        return $address;
    }

    /**
     * The place and the label, and the type of an address that is not the
     * billing one; the billing address stays the billing one.
     *
     * @param string $type delivery, postal or other; ignored for the billing address
     *
     * @throws PartyNotAllowed    for a guest
     * @throws UnknownParty       when the address is not one of the contact's
     * @throws ContactPartRefused with every field that is wrong
     */
    public function change(Party $party, string $addressId, string $type, string $street, string $city, string $postalCode, string $countryCode, string $label): PartyAddress
    {
        $this->access->denyUnlessMayChange();
        $stored = $this->stored($party, $addressId);
        $reasons = $this->check($street, $city, $postalCode, $countryCode, $label);
        $kind = AddressType::Billing === $stored->type() ? AddressType::Billing : AddressType::tryFrom($type);
        if (!$kind instanceof AddressType || (AddressType::Billing === $kind && AddressType::Billing !== $stored->type())) {
            $reasons['type'] = ContactPartRefused::ADDRESS_TYPE;
        }
        if ([] !== $reasons || !$kind instanceof AddressType) {
            throw new ContactPartRefused($reasons);
        }
        $address = $this->address($street, $city, $postalCode, $countryCode);

        return $this->transaction->run(function () use ($party, $stored, $kind, $address, $label): PartyAddress {
            $this->changed($party);
            if ($kind === $stored->type()) {
                $stored->relocate($address);
                $stored->relabel($label);

                return $stored;
            }
            // The type is part of what an address is: another type is another address.
            $this->addresses->remove($stored);
            $replacement = new PartyAddress($stored->party(), $kind, $address, $label);
            $this->addresses->add($replacement);

            return $replacement;
        });
    }

    /**
     * @throws PartyNotAllowed    for a guest
     * @throws UnknownParty       when the address is not one of the contact's
     * @throws ContactPartRefused for the billing address
     */
    public function remove(Party $party, string $addressId): void
    {
        $this->access->denyUnlessMayChange();
        $address = $this->stored($party, $addressId);
        if (AddressType::Billing === $address->type()) {
            throw new ContactPartRefused(['type' => ContactPartRefused::ADDRESS_TYPE]);
        }
        $this->transaction->run(function () use ($party, $address): void {
            $this->addresses->remove($address);
            $this->changed($party);
        });
    }

    /**
     * @throws UnknownParty when the address is not one of the contact's
     */
    public function stored(Party $party, string $addressId): PartyAddress
    {
        return array_find($this->details->addresses($party), static fn (PartyAddress $address): bool => $address->id() === $addressId)
            ?? throw new UnknownParty('The contact has no address with this id.');
    }

    /**
     * @return array<string, string>
     */
    private function check(string $street, string $city, string $postalCode, string $countryCode, string $label): array
    {
        $reasons = [];
        foreach (['street' => [$street, PartyRules::MAX_STREET], 'city' => [$city, PartyRules::MAX_CITY], 'postalCode' => [$postalCode, PartyRules::MAX_POSTAL_CODE]] as $field => [$value, $max]) {
            if ('' === trim($value)) {
                $reasons[$field] = ContactPartRefused::REQUIRED;
            } elseif (mb_strlen(trim($value)) > $max) {
                $reasons[$field] = ContactPartRefused::TOO_LONG;
            }
        }
        if (!Countries::supports(strtoupper(trim($countryCode)))) {
            $reasons['countryCode'] = '' === trim($countryCode) ? ContactPartRefused::REQUIRED : ContactPartRefused::COUNTRY;
        }
        if (mb_strlen(trim($label)) > self::MAX_LABEL) {
            $reasons['label'] = ContactPartRefused::TOO_LONG;
        }

        return $reasons;
    }

    private function address(string $street, string $city, string $postalCode, string $countryCode): Address
    {
        return new Address(trim($street), trim($city), trim($postalCode), strtoupper(trim($countryCode)));
    }

    /**
     * Webhooks and listeners learn that the contact changed, as they do for its form.
     */
    private function changed(Party $party): void
    {
        $this->events->record(new ContactUpdated($party->id(), ContactKind::from($party->kind()->value)));
    }
}
