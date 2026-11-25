<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'inbox.items.link',
    method: 'POST',
    path: '/api/v1/inbox/items/{itemId}/outputs',
    tool: 'foresi_link_inbox_item',
    scope: 'inbox:write',
    title: 'Link an inbox item to a record',
    description: 'Links one of your inbox items to a record made elsewhere, such as a deal: the record becomes an output of the item and the e-mail shows on the record\'s activity timeline. The first link makes the e-mail activity (subject, sender, date and text of the mail, linked to the matched contact too); later links add to that one activity. The kind is one activities link to; a kind_unknown refusal lists the kinds offered. Refused with kind_unknown for a kind no module offers, record_not_linkable for a record you may not link to, already_linked when it is an output already, and type_unknown while the activity type email is archived.',
    status: 201,
    response: 'InboxItem',
    staffOnly: true,
)]
final readonly class LinkItemInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $itemId,
        #[Field('The kind of the record, such as deal, project or contact.', maxLength: 40)]
        public string $kind,
        #[Field('The id of the record.', format: 'uuid')]
        public string $id,
    ) {
    }
}
