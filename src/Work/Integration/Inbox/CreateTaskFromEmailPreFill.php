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
use Foresi\Work\Application\ClientProjects;
use Foresi\Work\Domain\Task;

/**
 * The task starts as the mail: its subject as the title, its text as the
 * description (plain text, cut), the project of the client when it is one.
 */
final readonly class CreateTaskFromEmailPreFill implements InboxPreFill
{
    /** Characters of the mail text taken into the description. */
    public const int MAX_DESCRIPTION = 10_000;

    public function __construct(private InboxItems $items, private ClientProjects $projects)
    {
    }

    public function operation(): string
    {
        return 'inbox.actions.create_task';
    }

    public function prefill(InboxItemView $item): PreFill
    {
        $values = [
            'title' => mb_substr(trim($item->subject), 0, Task::MAX_TITLE_LENGTH),
            'description' => mb_substr(trim($item->text ?? ''), 0, self::MAX_DESCRIPTION),
            'copyAttachments' => [] !== $item->attachments,
            'markDone' => true,
        ];
        $project = $this->items->suggestedRecord($item->id, ActiveProjectSuggester::KIND);
        if ($project instanceof SuggestedRecord) {
            $values['projectId'] = $project->id;
        }

        return new PreFill($values, ['projectId' => $this->projects->choices($item->contactId, $item->companyId)]);
    }
}
