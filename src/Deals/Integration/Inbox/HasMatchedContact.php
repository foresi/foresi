<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Integration\Inbox;

use Foresi\Inbox\Contract\InboxActionCondition;
use Foresi\Inbox\Contract\InboxItemView;

/**
 * A deal is made for a client, so the action is offered only for an item
 * matched to a contact.
 */
final readonly class HasMatchedContact implements InboxActionCondition
{
    public function applies(InboxItemView $item): bool
    {
        return null !== $item->contactId;
    }
}
