<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'inbox.items.match',
    method: 'PUT',
    path: '/api/v1/inbox/items/{itemId}/contact',
    tool: 'foresi_match_inbox_item',
    scope: 'inbox:write',
    title: 'Match an inbox item to a contact',
    description: 'Sets the contact one of your inbox items is about, by hand, or none with contact_id null. A person linked to exactly one company gives that company too. Refused with contact_unknown for no such contact and contact_archived for an archived one. Find the contact with foresi_search_contacts.',
    response: 'InboxItem',
    staffOnly: true,
)]
final readonly class MatchItemInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $itemId,
        #[Field('The id of the contact, a person, sole trader or company; null for none.', format: 'uuid')]
        public ?string $contactId,
    ) {
    }
}
