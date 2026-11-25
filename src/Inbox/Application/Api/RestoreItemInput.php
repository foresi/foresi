<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'inbox.items.restore',
    method: 'POST',
    path: '/api/v1/inbox/items/{itemId}/restore',
    tool: 'foresi_restore_inbox_item',
    scope: 'inbox:write',
    title: 'Restore an inbox item',
    description: 'Brings one of your inbox items back from the trash as it was. Restoring one not in the trash changes nothing.',
    response: 'InboxItem',
    staffOnly: true,
)]
final readonly class RestoreItemInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $itemId,
    ) {
    }
}
