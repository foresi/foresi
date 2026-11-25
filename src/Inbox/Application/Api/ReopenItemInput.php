<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'inbox.items.reopen',
    method: 'POST',
    path: '/api/v1/inbox/items/{itemId}/reopen',
    tool: 'foresi_reopen_inbox_item',
    scope: 'inbox:write',
    title: 'Reopen an inbox item',
    description: 'Takes one of your done or discarded inbox items back to new; a discarded one keeps its mail again. Refused with already_new when it is new.',
    response: 'InboxItem',
    staffOnly: true,
)]
final readonly class ReopenItemInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $itemId,
    ) {
    }
}
