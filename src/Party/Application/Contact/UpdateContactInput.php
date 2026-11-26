<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\Contact;

use Foresi\Party\Application\PartyRules;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Shared\Application\Field\CustomFieldsOf;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A partial change of a contact over the API and MCP (ADR 0032): a field left
 * out keeps its value, an optional field sent as null is cleared. The kind of a
 * contact does not change.
 */
#[Operation(
    name: 'contacts.update',
    method: 'PATCH',
    path: '/api/v1/contacts/{contactId}',
    tool: 'foresi_update_contact',
    scope: 'contacts:write',
    title: 'Update a contact',
    description: 'Changes the fields given of a contact and keeps the rest; an optional field sent as null is cleared. The kind does not change. A registration number or VAT id another contact has already is refused with duplicate and existing_ids.',
    response: 'ContactDetail',
)]
final readonly class UpdateContactInput
{
    /**
     * @param list<string>|Omitted         $roles
     * @param array<string, mixed>|Omitted $customFields
     */
    public function __construct(
        #[Field('The id of the contact.', format: 'uuid')]
        public string $contactId,
        #[Field('The name shown in the address book and on documents.', maxLength: PartyRules::MAX_NAME)]
        public string|Omitted $displayName = new Omitted(),
        #[Field('The street and number of the billing address.', maxLength: PartyRules::MAX_STREET)]
        public string|Omitted $street = new Omitted(),
        #[Field('The city of the billing address.', maxLength: PartyRules::MAX_CITY)]
        public string|Omitted $city = new Omitted(),
        #[Field('The postal code of the billing address.', maxLength: PartyRules::MAX_POSTAL_CODE)]
        public string|Omitted $postalCode = new Omitted(),
        #[Field('The country of the billing address, ISO 3166-1 alpha-2, such as DE.', maxLength: PartyRules::COUNTRY_CODE_LENGTH)]
        public string|Omitted $countryCode = new Omitted(),
        #[Field('The company registration number; null clears it.', maxLength: PartyRules::MAX_REGISTRATION_NUMBER)]
        public string|Omitted|null $registrationNumber = new Omitted(),
        #[Field('The country that issued the registration number, ISO 3166-1 alpha-2; null takes the country of the billing address.', maxLength: PartyRules::COUNTRY_CODE_LENGTH)]
        public string|Omitted|null $registrationCountry = new Omitted(),
        #[Field('The VAT id; null clears it.', maxLength: PartyRules::MAX_VAT_ID)]
        public string|Omitted|null $vatId = new Omitted(),
        #[Field('The general e-mail address; null clears it.', maxLength: PartyRules::MAX_EMAIL)]
        public string|Omitted|null $email = new Omitted(),
        #[Field('The phone number; null clears it.', maxLength: PartyRules::MAX_PHONE)]
        public string|Omitted|null $phone = new Omitted(),
        #[Field('The mobile number; null clears it.', maxLength: PartyRules::MAX_PHONE)]
        public string|Omitted|null $mobile = new Omitted(),
        #[Field('The website; null clears it.', maxLength: PartyRules::MAX_WEBSITE)]
        public string|Omitted|null $website = new Omitted(),
        #[Field('What the contact is to the organisation; replaces the roles it has.', enum: ['customer', 'supplier', 'partner', 'authority'], items: 'string')]
        public array|Omitted $roles = new Omitted(),
        #[Field('A note for the staff, never printed; null clears it.')]
        public string|Omitted|null $privateNote = new Omitted(),
        #[Field('The custom fields to change, by key, as foresi_list_fields lists them for contacts; null clears one, a field left out keeps its value.', items: Field::ONE_OBJECT)]
        #[CustomFieldsOf(ContactExtension::RECORD)]
        public array|Omitted $customFields = new Omitted(),
    ) {
    }
}
