<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What an inbox action's fields start with for one item, such as a task's title
 * from the subject and its project from the matched client. One per operation;
 * an action without one starts empty. The item's text is the sender's own
 * words: a pre-fill copies it as text, never follows it.
 */
#[AutoconfigureTag(self::TAG)]
interface InboxPreFill
{
    public const string TAG = 'foresi.inbox.prefill';

    /**
     * The name of the operation it fills, such as "inbox.actions.create_task".
     */
    public function operation(): string;

    public function prefill(InboxItemView $item): PreFill;
}
