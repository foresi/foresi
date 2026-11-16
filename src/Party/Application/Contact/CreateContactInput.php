<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Contact;

use App\Shared\Application\Api\Field;
use App\Shared\Application\Api\Operation;

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
        #[Field('The name shown in the address book and on documents.', maxLength: 255)]
        public string $displayName,
        #[Field('The street and number of the billing address.', maxLength: 255)]
        public string $street,
        #[Field('The city of the billing address.', maxLength: 128)]
        public string $city,
        #[Field('The postal code of the billing address.', maxLength: 32)]
        public string $postalCode,
        #[Field('The country of the billing address, ISO 3166-1 alpha-2, such as DE.', maxLength: 2)]
        public string $countryCode,
        #[Field('The company registration number, empty for none.', maxLength: 64)]
        public string $registrationNumber = '',
        #[Field('The country that issued the registration number, ISO 3166-1 alpha-2; the country of the billing address when left out.', maxLength: 2)]
        public ?string $registrationCountry = null,
        #[Field('The VAT id: two letters of the country, then the number; empty for none.', maxLength: 32)]
        public string $vatId = '',
        #[Field('The general e-mail address, empty for none.', maxLength: 254)]
        public string $email = '',
        #[Field('The phone number, empty for none.', maxLength: 64)]
        public string $phone = '',
        #[Field('The mobile number, empty for none.', maxLength: 64)]
        public string $mobile = '',
        #[Field('The website, empty for none.', maxLength: 255)]
        public string $website = '',
        #[Field('What the contact is to the organisation.', items: 'string', enum: ['customer', 'supplier', 'partner', 'authority'])]
        public array $roles = [],
        #[Field('A note for the staff, never printed.')]
        public string $privateNote = '',
    ) {
    }
}
