<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application\Api;

use Foresi\Shared\Application\TaskReferenceSyntax;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * An action item made over the API and MCP (ADR 0032): a task in Work and its
 * link to the meeting, and its token in the minutes, in one transaction.
 */
#[Operation(
    name: 'meetings.action_items.create',
    method: 'POST',
    path: '/api/v1/meetings/{meetingId}/action-items',
    tool: 'foresi_create_meeting_action_item',
    scope: 'meetings:write',
    title: 'Create an action item of a meeting',
    description: 'Creates a task in a project (project_id, from foresi_search_projects) as an action item of the meeting, with Work\'s own checks, as foresi_create_task does: responsible_id is someone who sees the project (foresi_list_project_people), due_on a day, private hides it from everyone it is not shared with. The task is linked to the meeting, and the minutes get its token "[text](task:id)": given quote, the first exact occurrence of the quote in the minutes, on one line and outside another token, becomes the token\'s text, refused with quote_not_found when there is none; without quote the token is appended as a new list item with the title. The minutes\' version goes up by one. Taking the token out of the minutes later keeps the task and its link.',
    status: 201,
    response: 'Meeting',
    staffOnly: true,
)]
final readonly class CreateActionItemInput
{
    public function __construct(
        #[Field('The id of the meeting.', format: 'uuid')]
        public string $meetingId,
        #[Field('The id of the project the task goes in.', format: 'uuid')]
        public string $projectId,
        #[Field('What is to be done, the title of the task.', maxLength: 500)]
        public string $title,
        #[Field('The person responsible, by the id foresi_list_project_people gives; nobody when left out.', format: 'uuid')]
        public ?string $responsibleId = null,
        #[Field('The day it is due, YYYY-MM-DD; none when left out.', format: 'date')]
        public ?string $dueOn = null,
        #[Field('Seen only by the people it is shared with, and by you.')]
        public bool $private = false,
        #[Field('The text of the minutes that becomes the token, exactly as it is written there, on one line; the token is appended as a list item when left out.', maxLength: TaskReferenceSyntax::MAX_TEXT)]
        public ?string $quote = null,
    ) {
    }
}
