<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

use Foresi\Inbox\Application\InboxActions;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\PreFill;

/**
 * The pre-fill that the container builds for an action, asked for an item made by hand (ItemViews).
 */
trait ContainerPreFills
{
    private function prefillOf(string $operation, InboxItemView $item): PreFill
    {
        $actions = self::getContainer()->get(InboxActions::class);
        $action = $actions->get($operation);
        self::assertNotNull($action, $operation.' is declared.');

        return $actions->prefill($action, $item);
    }
}
