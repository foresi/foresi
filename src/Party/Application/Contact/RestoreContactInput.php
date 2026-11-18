<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\Contact;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Restores an archived contact over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'contacts.restore',
    method: 'POST',
    path: '/api/v1/contacts/{contactId}/restore',
    tool: 'foresi_restore_contact',
    scope: 'contacts:write',
    title: 'Restore a contact',
    description: 'Restores an archived contact to the lists. Restoring a contact that is not archived changes nothing.',
    response: 'ContactDetail',
)]
final readonly class RestoreContactInput
{
    public function __construct(
        #[Field('The id of the contact.', format: 'uuid')]
        public string $contactId,
    ) {
    }
}
