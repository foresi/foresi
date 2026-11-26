<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\BrokenAction;

use Foresi\Inbox\Contract\InboxAction;
use Foresi\Inbox\Contract\InboxActionInput;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * An inbox action whose input names the item otherwise than inboxItemId: never wired, the build refuses it.
 */
#[Operation(name: 'inbox.actions.broken', method: 'POST', path: '/api/v1/inbox/items/{itemId}/broken', tool: 'foresi_broken_from_email', scope: 'activities:write', title: 'Broken', description: 'Broken on purpose.')]
#[InboxAction(label: 'inbox_fixture.action.broken', kind: 'broken')]
final readonly class NoItemIdInput implements InboxActionInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $itemId,
    ) {
    }

    public function inboxItemId(): string
    {
        return $this->itemId;
    }

    public function markDone(): bool
    {
        return true;
    }

    public function relatedRecords(): array
    {
        return [];
    }
}
