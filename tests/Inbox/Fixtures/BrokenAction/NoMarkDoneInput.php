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
 * An inbox action without the field markDone: never wired, the build refuses it.
 */
#[Operation(name: 'inbox.actions.no_mark_done', method: 'POST', path: '/api/v1/inbox/items/{inboxItemId}/no-mark-done', tool: 'foresi_no_mark_done_from_email', scope: 'activities:write', title: 'Broken', description: 'Broken on purpose.')]
#[InboxAction(label: 'inbox_fixture.action.broken', kind: 'broken')]
final readonly class NoMarkDoneInput implements InboxActionInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $inboxItemId,
    ) {
    }

    public function inboxItemId(): string
    {
        return $this->inboxItemId;
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
