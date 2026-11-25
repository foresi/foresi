<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\Contact;

use Foresi\Party\Application\PartyRules;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A new contact over the API and MCP (ADR 0032). A contact always has a
 * billing address; a company needs a registration number or a VAT id. A
 * registration number or VAT id the organisation has already is refused as a
 * duplicate with the ids it matched.
 */
#[Operation(
    name: 'contacts.create',
    method: 'POST',
    path: '/api/v1/contacts',
    tool: 'foresi_create_contact',
    scope: 'contacts:write',
    title: 'Create a contact',
    description: 'Creates a contact in the address book: a person, a sole trader or a company, with its billing address. A company needs a registration number or a VAT id. If the organisation has a contact with the same registration number or VAT id already, it is refused with duplicate and existing_ids; then read that contact and update it instead. Search first with foresi_search_contacts, which also matches e-mails.',
    status: 201,
    response: 'ContactDetail',
)]
final readonly class CreateContactInput
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
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
        #[Field('The country of the billing address, ISO 3166-1 alpha-2, such as DE.', maxLength: PartyRules::COUNTRY_CODE_LENGTH)]
        public string $countryCode,
        #[Field('The company registration number, empty for none.', maxLength: PartyRules::MAX_REGISTRATION_NUMBER)]
        public string $registrationNumber = '',
        #[Field('The country that issued the registration number, ISO 3166-1 alpha-2; the country of the billing address when left out.', maxLength: PartyRules::COUNTRY_CODE_LENGTH)]
        public ?string $registrationCountry = null,
        #[Field('The VAT id: two letters of the country, then the number; empty for none.', maxLength: PartyRules::MAX_VAT_ID)]
        public string $vatId = '',
        #[Field('The general e-mail address, empty for none.', maxLength: PartyRules::MAX_EMAIL)]
        public string $email = '',
        #[Field('The phone number, empty for none.', maxLength: PartyRules::MAX_PHONE)]
        public string $phone = '',
        #[Field('The mobile number, empty for none.', maxLength: PartyRules::MAX_PHONE)]
        public string $mobile = '',
        #[Field('The website, empty for none.', maxLength: PartyRules::MAX_WEBSITE)]
        public string $website = '',
        #[Field('What the contact is to the organisation.', enum: ['customer', 'supplier', 'partner', 'authority'], items: 'string')]
        public array $roles = [],
        #[Field('A note for the staff, never printed.')]
        public string $privateNote = '',
    ) {
    }
}
