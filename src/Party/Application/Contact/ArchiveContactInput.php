<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\Contact;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Archives a contact over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'contacts.archive',
    method: 'POST',
    path: '/api/v1/contacts/{contactId}/archive',
    tool: 'foresi_archive_contact',
    scope: 'contacts:write',
    title: 'Archive a contact',
    description: 'Archives a contact: it keeps its data and stays on its documents, and only the lists hide it. Archiving an archived contact changes nothing.',
    response: 'ContactDetail',
)]
final readonly class ArchiveContactInput
{
    public function __construct(
        #[Field('The id of the contact.', format: 'uuid')]
        public string $contactId,
    ) {
    }
}
