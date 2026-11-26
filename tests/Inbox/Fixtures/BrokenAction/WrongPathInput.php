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
 * An inbox action whose path does not name the item: never wired, the build refuses it.
 */
#[Operation(name: 'inbox.actions.wrong_path', method: 'POST', path: '/api/v1/inbox/wrong-path', tool: 'foresi_wrong_path_from_email', scope: 'activities:write', title: 'Broken', description: 'Broken on purpose.')]
#[InboxAction(label: 'inbox_fixture.action.broken', kind: 'broken')]
final readonly class WrongPathInput implements InboxActionInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $inboxItemId,
        #[Field('Mark the item done after the action.')]
        public bool $markDone = true,
    ) {
    }

    public function inboxItemId(): string
    {
        return $this->inboxItemId;
    }

    public function markDone(): bool
    {
        return $this->markDone;
    }

    public function relatedRecords(): array
    {
        return [];
    }
}
