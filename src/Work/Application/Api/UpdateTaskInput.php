<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Work\Domain\Task;

/**
 * A partial change of a task over the API and MCP (ADR 0032): a field left out
 * keeps its value, an optional field sent as null is cleared. Each field goes
 * through the use case the task page saves it with, in the order of the fields.
 */
#[Operation(
    name: 'tasks.update',
    method: 'PATCH',
    path: '/api/v1/tasks/{taskId}',
    tool: 'foresi_update_task',
    scope: 'tasks:write',
    title: 'Update a task',
    description: 'Changes the fields given of a task and keeps the rest; responsible_id, due_on and section_id sent as null are cleared. '
        .'To complete a task, set status_id to a status of type done or closed of its project (foresi_get_project lists them); to reopen it, to one of type open. '
        .'label_ids replaces the labels of the task. A new responsible is told and follows the task; the watchers hear of a new status. Each field changed records its own change.',
    response: 'TaskDetail',
)]
final readonly class UpdateTaskInput
{
    /**
     * @param list<string>|Omitted $labelIds
     */
    public function __construct(
        #[Field('The id of the task.', format: 'uuid')]
        public string $taskId,
        #[Field('What is to be done.', maxLength: Task::MAX_TITLE_LENGTH)]
        public string|Omitted $title = new Omitted(),
        #[Field('More about it, in Markdown; an empty text clears it.')]
        public string|Omitted $description = new Omitted(),
        #[Field('A status of the project of the task.', format: 'uuid')]
        public string|Omitted $statusId = new Omitted(),
        #[Field('The person responsible, their id as foresi_list_project_people gives it; null for nobody.', format: 'uuid')]
        public string|Omitted|null $responsibleId = new Omitted(),
        #[Field('The day it is due, YYYY-MM-DD; null for none.', format: 'date')]
        public string|Omitted|null $dueOn = new Omitted(),
        #[Field('How urgent it is.', enum: ['none', 'low', 'medium', 'high', 'urgent'])]
        public string|Omitted $priority = new Omitted(),
        #[Field('A section of its project; null for none.', format: 'uuid')]
        public string|Omitted|null $sectionId = new Omitted(),
        #[Field('The labels of the task, by id; replaces those it has.', items: 'string')]
        public array|Omitted $labelIds = new Omitted(),
    ) {
    }
}
