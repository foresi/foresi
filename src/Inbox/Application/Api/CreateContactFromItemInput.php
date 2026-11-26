<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\Api;

use Foresi\Party\Application\PartyRules;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * The fields of the address book's create (a contact has a billing address),
 * with the e-mail taken from the sender when left out.
 */
#[Operation(
    name: 'inbox.items.create_contact',
    method: 'POST',
    path: '/api/v1/inbox/items/{itemId}/contact',
    tool: 'foresi_create_contact_from_inbox_item',
    scope: 'inbox:write',
    title: 'Create a contact from an inbox item',
    description: 'Creates a contact for the sender of one of your inbox items, as foresi_create_contact does, and matches the item to it. The token needs contacts:write too. The e-mail is the sender\'s (the original sender of a forward) unless given. Search with foresi_search_contacts first: a company or sole trader whose registration number or VAT id is known is refused with duplicate and the existing_ids; match the item to it with foresi_match_inbox_item instead. The address book\'s other refusals come unchanged.',
    status: 201,
    response: 'InboxItem',
    staffOnly: true,
)]
final readonly class CreateContactFromItemInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $itemId,
        #[Field('What the contact is.', enum: ['person', 'sole_trader', 'company'])]
        public string $kind,
        #[Field('The name shown in the address book and on documents.', maxLength: PartyRules::MAX_NAME)]
        public string $displayName,
        #[Field('The street and number of the billing address.', maxLength: PartyRules::MAX_STREET)]
        public string $street,
        #[Field('The city of the billing address.', maxLength: PartyRules::MAX_CITY)]
        public string $city,
        #[Field('The postal code of the billing address.', maxLength: PartyRules::MAX_POSTAL_CODE)]
        public string $postalCode,
        #[Field('The country of the billing address, ISO 3166-1 alpha-2 such as CZ.', maxLength: PartyRules::COUNTRY_CODE_LENGTH)]
        public string $countryCode,
        #[Field('The registration number; a company needs it or a VAT id; empty for none.', maxLength: PartyRules::MAX_REGISTRATION_NUMBER)]
        public string $registrationNumber = '',
        #[Field('The VAT id: two letters of the country, then the number; empty for none.', maxLength: PartyRules::MAX_VAT_ID)]
        public string $vatId = '',
        #[Field('The e-mail; the sender\'s when left out or null.', maxLength: PartyRules::MAX_EMAIL)]
        public ?string $email = null,
        #[Field('The phone number, empty for none.', maxLength: PartyRules::MAX_PHONE)]
        public string $phone = '',
    ) {
    }
}
