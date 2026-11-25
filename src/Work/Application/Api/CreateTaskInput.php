<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Task;

/**
 * A new task over the API and MCP (ADR 0032), as the project page adds one.
 */
#[Operation(
    name: 'tasks.create',
    method: 'POST',
    path: '/api/v1/tasks',
    tool: 'foresi_create_task',
    scope: 'tasks:write',
    title: 'Create a task',
    description: 'Adds a task at the end of a project the person sees; with parent_id it is a subtask of a task of the same project that is not a subtask itself. '
        .'Without status_id it starts in the first open status of the project; foresi_get_project lists the statuses and sections. '
        .'The responsible must see the task: find them with foresi_list_project_people. Whoever creates the task and its responsible follow it, and the responsible is told. '
        .'An archived project takes no tasks (archived).',
    status: 201,
    response: 'TaskDetail',
)]
final readonly class CreateTaskInput
{
    /**
     * @param list<string> $labelIds
     */
    public function __construct(
        #[Field('The project of the task.', format: 'uuid')]
        public string $projectId,
        #[Field('What is to be done.', maxLength: Task::MAX_TITLE_LENGTH)]
        public string $title,
        #[Field('More about it, in Markdown.')]
        public string $description = '',
        #[Field('A section of the project; none when left out.', format: 'uuid')]
        public ?string $sectionId = null,
        #[Field('The task this is a subtask of.', format: 'uuid')]
        public ?string $parentId = null,
        #[Field('A status of the project; its first open one when left out.', format: 'uuid')]
        public ?string $statusId = null,
        #[Field('The person responsible, their id as foresi_list_project_people gives it; nobody when left out.', format: 'uuid')]
        public ?string $responsibleId = null,
        #[Field('The day it is due, YYYY-MM-DD.', format: 'date')]
        public ?string $dueOn = null,
        #[Field('How urgent it is.', enum: ['none', 'low', 'medium', 'high', 'urgent'])]
        public string $priority = Priority::None->value,
        #[Field('The labels of the task, by id as foresi_list_labels gives them.', items: 'string')]
        public array $labelIds = [],
    ) {
    }
}
