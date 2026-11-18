<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\Contact;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Takes a contact person off a company or sole trader over the API and MCP
 * (ADR 0032). Both contacts stay.
 */
#[Operation(
    name: 'contacts.unlink_person',
    method: 'DELETE',
    path: '/api/v1/contacts/{contactId}/contact-persons/{personId}',
    tool: 'foresi_unlink_contact_person',
    scope: 'contacts:write',
    title: 'Unlink a contact person',
    description: 'Takes a contact person off a company or sole trader; both contacts stay. A person who is not linked is refused with not_linked.',
    response: 'ContactDetail',
)]
final readonly class UnlinkContactPersonInput
{
    public function __construct(
        #[Field('The id of the company or sole trader.', format: 'uuid')]
        public string $contactId,
        #[Field('The id of the person.', format: 'uuid')]
        public string $personId,
    ) {
    }
}
