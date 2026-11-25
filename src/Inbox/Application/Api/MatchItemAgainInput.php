<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'inbox.items.match_again',
    method: 'POST',
    path: '/api/v1/inbox/items/{itemId}/match-again',
    tool: 'foresi_match_inbox_item_again',
    scope: 'inbox:write',
    title: 'Match an inbox item again',
    description: 'Matches one of your inbox items to its sender again, as on arrival, after the address book changed: exactly one contact with the address, else exactly one company of the domain when the sender is verified, else none. A hand-picked contact is replaced.',
    response: 'InboxItem',
    staffOnly: true,
)]
final readonly class MatchItemAgainInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $itemId,
    ) {
    }
}
