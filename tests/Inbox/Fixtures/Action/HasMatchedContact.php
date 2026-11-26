<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\Action;

use Foresi\Inbox\Contract\InboxActionCondition;
use Foresi\Inbox\Contract\InboxItemView;

/**
 * Only an item matched to a contact. It keeps the last item it was asked about, so that a
 * test sees what the runner shows a condition.
 */
final class HasMatchedContact implements InboxActionCondition
{
    private static ?InboxItemView $seen = null;

    public static function lastSeen(): ?InboxItemView
    {
        return self::$seen;
    }

    public static function forget(): void
    {
        self::$seen = null;
    }

    public function applies(InboxItemView $item): bool
    {
        self::$seen = $item;

        return null !== $item->contactId;
    }
}
