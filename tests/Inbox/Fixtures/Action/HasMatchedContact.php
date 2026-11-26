<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\Action;

use Foresi\Inbox\Contract\InboxActionCondition;
use Foresi\Inbox\Contract\InboxItemView;

/**
 * Only an item matched to a contact.
 */
final readonly class HasMatchedContact implements InboxActionCondition
{
    public function applies(InboxItemView $item): bool
    {
        return null !== $item->contactId;
    }
}
