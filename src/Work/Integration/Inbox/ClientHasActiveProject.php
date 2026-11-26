<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Inbox;

use Foresi\Inbox\Contract\InboxActionCondition;
use Foresi\Inbox\Contract\InboxItemView;

/**
 * Hours are approved for a project of a client, so the action is offered only
 * for an item whose matched client has an active project the person sees.
 */
final readonly class ClientHasActiveProject implements InboxActionCondition
{
    public function __construct(private ClientProjects $projects)
    {
    }

    public function applies(InboxItemView $item): bool
    {
        return [] !== $this->projects->of($item->contactId, $item->companyId);
    }
}
