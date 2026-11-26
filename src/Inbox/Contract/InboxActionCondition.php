<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * When an inbox action applies to an item, such as only to an item matched to a
 * contact; named by InboxAction::$appliesTo. Inbox asks it before it offers the
 * action and again before it runs it, which is refused with
 * action_not_applicable when it does not apply.
 */
#[AutoconfigureTag(self::TAG)]
interface InboxActionCondition
{
    public const string TAG = 'foresi.inbox.action_condition';

    public function applies(InboxItemView $item): bool;
}
