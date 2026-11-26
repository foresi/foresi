<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\BrokenAction;

use Foresi\Inbox\Contract\InboxAction;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * An inbox action whose input does not implement InboxActionInput: never wired, the build refuses it.
 */
#[Operation(name: 'inbox.actions.not_an_action', method: 'POST', path: '/api/v1/inbox/items/{inboxItemId}/not-an-action', tool: 'foresi_not_an_action_from_email', scope: 'activities:write', title: 'Broken', description: 'Broken on purpose.')]
#[InboxAction(label: 'inbox_fixture.action.broken', kind: 'broken')]
final readonly class NotAnActionInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $inboxItemId,
    ) {
    }
}
