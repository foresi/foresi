<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

/**
 * Declares an operation as an action on an inbox item, such as "Create task":
 * put it next to #[Operation] on the input class, which implements
 * InboxActionInput and has a field inboxItemId. The item shows the action
 * (available_actions over REST and MCP), and when it succeeds Inbox records the
 * record it made as an output of the item, under $kind, by the id in the
 * answer's data. The container build fails for an input declared otherwise.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class InboxAction
{
    /**
     * @param string                                  $label     a translation key of the messages domain, such as "work.inbox_action.create_task"
     * @param string                                  $kind      the kind the made record is stored under as an output, the kind an ActivityTarget knows it by when one does, such as task or deal
     * @param class-string<InboxActionCondition>|null $appliesTo the condition the item must meet for the action to be offered and run; null for every item
     * @param int                                     $weight    the order among the actions, the lowest first
     */
    public function __construct(
        public string $label,
        public string $kind,
        public ?string $appliesTo = null,
        public int $weight = 100,
    ) {
    }
}
