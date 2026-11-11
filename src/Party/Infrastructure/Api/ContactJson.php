<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Api;

use App\Party\Application\Query\ContactAddress;
use App\Party\Application\Query\ContactBankAccount;
use App\Party\Application\Query\ContactDetail;
use App\Party\Application\Query\ContactEmail;
use App\Party\Application\Query\ContactPersonLink;
use App\Party\Application\Query\ContactRow;
use App\Party\Domain\Address;
use App\Party\Domain\PartyRole;

/**
 * Contacts of the address book as the REST API and the MCP tools write them. The
 * private note and the history stay on the screen.
 */
final class ContactJson
{
    /**
     * @return array<string, mixed>
     */
    public static function row(ContactRow $contact): array
    {
        return [
            'id' => $contact->id,
            'kind' => $contact->kind->value,
            'name' => $contact->name,
            'roles' => array_map(static fn (PartyRole $role): string => $role->value, $contact->roles),
            'registration_country' => $contact->registrationCountry,
            'registration_number' => $contact->registrationNumber,
            'vat_id' => $contact->vatId,
            'email' => $contact->email,
            'billing_address' => $contact->billingAddress instanceof Address ? self::address($contact->billingAddress) : null,
            'archived' => $contact->archived,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(ContactDetail $detail): array
    {
        return self::row($detail->contact) + [
            'phone' => $detail->phone,
            'mobile' => $detail->mobile,
            'website' => $detail->website,
            'unreliable_payer' => $detail->unreliablePayer,
            'addresses' => array_map(static fn (ContactAddress $address): array => ['id' => $address->id, 'type' => $address->type->value, 'label' => $address->label] + self::address($address->address), $detail->addresses),
            'emails' => array_map(static fn (ContactEmail $email): array => ['id' => $email->id, 'address' => $email->address, 'purpose' => $email->purpose->value], $detail->emails),
            'bank_accounts' => array_map(static fn (ContactBankAccount $account): array => ['id' => $account->id, 'iban' => $account->iban, 'bic' => $account->bic, 'label' => $account->label, 'primary' => $account->primary], $detail->bankAccounts),
            'contact_persons' => array_map(static fn (ContactPersonLink $link): array => [
                'id' => $link->id,
                'company' => ['id' => $link->companyId, 'name' => $link->companyName],
                'person' => ['id' => $link->personId, 'name' => $link->personName],
                'position' => $link->position,
                'primary' => $link->primary,
            ], $detail->contactLinks),
        ];
    }

    /**
     * @return array{street: string, city: string, postal_code: string, country_code: string}
     */
    private static function address(Address $address): array
    {
        return ['street' => $address->street, 'city' => $address->city, 'postal_code' => $address->postalCode, 'country_code' => $address->countryCode];
    }
}
