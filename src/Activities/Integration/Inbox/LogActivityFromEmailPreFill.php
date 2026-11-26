<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Integration\Inbox;

use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Application\ActivityTypeNames;
use Foresi\Activities\Domain\Activity;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxPreFill;
use Foresi\Inbox\Contract\PreFill;

/**
 * The activity starts as the mail: its subject; the type is chosen among the
 * types in use, and preselected only when there is exactly one.
 */
final readonly class LogActivityFromEmailPreFill implements InboxPreFill
{
    public function __construct(private ActivityReads $reads, private ActivityTypeNames $names)
    {
    }

    public function operation(): string
    {
        return 'inbox.actions.log_activity';
    }

    public function prefill(InboxItemView $item): PreFill
    {
        $values = ['subject' => mb_substr(trim($item->subject), 0, Activity::MAX_SUBJECT), 'done' => false, 'markDone' => true];
        $choices = [];
        foreach ($this->reads->types() as $type) {
            $choices[$type->id()] = $this->names->of($type);
        }
        if (1 === \count($choices)) {
            $values['typeId'] = array_key_first($choices);
        }

        return new PreFill($values, ['typeId' => $choices]);
    }
}
