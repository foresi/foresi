<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'inbox.items.discard',
    method: 'POST',
    path: '/api/v1/inbox/items/{itemId}/discard',
    tool: 'foresi_discard_inbox_item',
    scope: 'inbox:write',
    title: 'Discard an inbox item',
    description: 'Discards one of your inbox items as nothing to act on. Unless something came of it (an output), its mail is removed 30 days later. Refused with already_discarded when it is discarded. Take it back with foresi_reopen_inbox_item before then to keep the mail.',
    response: 'InboxItem',
    staffOnly: true,
)]
final readonly class DiscardItemInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $itemId,
    ) {
    }
}
