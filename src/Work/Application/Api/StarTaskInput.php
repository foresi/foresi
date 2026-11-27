<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Puts a task on the person's My priorities over the API and MCP, as its star does (plan 37.2).
 */
#[Operation(
    name: 'tasks.star',
    method: 'PUT',
    path: '/api/v1/tasks/{taskId}/star',
    tool: 'foresi_star_task',
    scope: 'tasks:write',
    title: 'Star a task',
    description: 'Puts a task on My priorities, the shortlist of the person of the token in their own order: at its end, or before before_task_id, another of their starred tasks. '
        .'Only that person sees their stars; the star stays when the task is reassigned or its due date changes, and goes when the task is finished, so a finished task is refused (star_finished). '
        .'Starring a starred task again keeps its place unless before_task_id moves it. List the shortlist with foresi_search_tasks with starred true and sort my_order.',
    response: 'TaskDetail',
)]
final readonly class StarTaskInput
{
    public function __construct(
        #[Field('The id of the task.', format: 'uuid')]
        public string $taskId,
        #[Field('A starred task to put it before; left out for the end.', format: 'uuid')]
        public ?string $beforeTaskId = null,
    ) {
    }
}
