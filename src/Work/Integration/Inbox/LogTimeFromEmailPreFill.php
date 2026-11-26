<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Inbox;

use Foresi\Inbox\Contract\InboxItems;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxPreFill;
use Foresi\Inbox\Contract\PreFill;
use Foresi\Inbox\Contract\SuggestedRecord;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Work\Domain\TimeEntry;

/**
 * The time starts as the mail: the day it was received (in the instance's
 * zone), its subject as the note, the project of the client when it is one.
 */
final readonly class LogTimeFromEmailPreFill implements InboxPreFill
{
    public function __construct(private InboxItems $items, private ClientProjects $projects, private InstanceClock $clock)
    {
    }

    public function operation(): string
    {
        return 'inbox.actions.log_time';
    }

    public function prefill(InboxItemView $item): PreFill
    {
        $values = [
            'workedOn' => $item->receivedAt->setTimezone($this->clock->timezone())->format('Y-m-d'),
            'note' => mb_substr(trim($item->subject), 0, TimeEntry::MAX_NOTE_LENGTH),
            'markDone' => true,
        ];
        $project = $this->items->suggestedRecord($item->id, ActiveProjectSuggester::KIND);
        if ($project instanceof SuggestedRecord) {
            $values['projectId'] = $project->id;
        }

        return new PreFill($values, ['projectId' => $this->projects->choices($item->contactId, $item->companyId)]);
    }
}
