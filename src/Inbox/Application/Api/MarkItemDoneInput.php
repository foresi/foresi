<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'inbox.items.done',
    method: 'POST',
    path: '/api/v1/inbox/items/{itemId}/done',
    tool: 'foresi_mark_inbox_item_done',
    scope: 'inbox:write',
    title: 'Mark an inbox item done',
    description: 'Marks one of your inbox items done: dealt with. A discarded item marked done keeps its mail again. Refused with already_done when it is done. '
        .'While suggestions on it wait for a decision it is refused with open_suggestions unless suggestions says what becomes of them: reject rejects them, keep leaves them open on the done item. Take it back with foresi_reopen_inbox_item.',
    response: 'InboxItem',
    staffOnly: true,
)]
final readonly class MarkItemDoneInput
{
    public const string REJECT = 'reject';
    public const string KEEP = 'keep';
    public const array SUGGESTIONS = [self::REJECT, self::KEEP];

    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $itemId,
        #[Field('What becomes of the suggestions on it that wait for a decision: reject them, or keep them open on the done item. Needed only while there are any.', enum: self::SUGGESTIONS)]
        public ?string $suggestions = null,
    ) {
    }
}
