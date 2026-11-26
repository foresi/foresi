<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'inbox.items.trash',
    method: 'POST',
    path: '/api/v1/inbox/items/{itemId}/trash',
    tool: 'foresi_trash_inbox_item',
    scope: 'inbox:write',
    title: 'Put an inbox item in the trash',
    description: 'Puts one of your inbox items in the trash: it leaves every list, and 30 days later it is removed for good, with its mail unless a record still uses its e-mail activity. Ask the person before you call it. Trashing it again changes nothing. Bring it back with foresi_restore_inbox_item.',
    destructive: true,
    response: 'InboxItem',
    staffOnly: true,
)]
final readonly class TrashItemInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $itemId,
    ) {
    }
}
