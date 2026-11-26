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
use Foresi\Work\Domain\ProjectBudgetChange;

/**
 * The change starts as the mail: dated today (in the instance's zone), its
 * subject as the note, the one active project of the client when there is one.
 * The hours are for the person to enter.
 */
final readonly class AddToBudgetFromEmailPreFill implements InboxPreFill
{
    public function __construct(private InboxItems $items, private ClientProjects $projects, private InstanceClock $clock)
    {
    }

    public function operation(): string
    {
        return 'inbox.actions.add_to_budget';
    }

    public function prefill(InboxItemView $item): PreFill
    {
        $values = [
            'effectiveOn' => $this->clock->today()->format('Y-m-d'),
            'note' => mb_substr(trim($item->subject), 0, ProjectBudgetChange::MAX_NOTE_LENGTH),
            'markDone' => true,
        ];
        $project = $this->items->suggestedRecord($item->id, ActiveProjectSuggester::KIND);
        if ($project instanceof SuggestedRecord) {
            $values['projectId'] = $project->id;
        }

        return new PreFill($values, ['projectId' => $this->projects->choices($item->contactId, $item->companyId)]);
    }
}
