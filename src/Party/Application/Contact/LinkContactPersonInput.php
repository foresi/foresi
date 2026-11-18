<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\Contact;

use Foresi\Party\Application\PartyRules;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Links a person to a company or sole trader over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'contacts.link_person',
    method: 'POST',
    path: '/api/v1/contacts/{contactId}/contact-persons',
    tool: 'foresi_link_contact_person',
    scope: 'contacts:write',
    title: 'Link a contact person',
    description: 'Links a contact of kind person to a company or sole trader as its contact person, with a position. A primary link replaces the previous primary one. A pair linked already is refused with already_linked.',
    response: 'ContactDetail',
)]
final readonly class LinkContactPersonInput
{
    public function __construct(
        #[Field('The id of the company or sole trader.', format: 'uuid')]
        public string $contactId,
        #[Field('The id of the person.', format: 'uuid')]
        public string $personId,
        #[Field('The position of the person at the company, such as Accountant.', maxLength: PartyRules::MAX_POSITION)]
        public string $position = '',
        #[Field('Whether the person is the primary contact of the company.')]
        public bool $primary = false,
    ) {
    }
}
