<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Puts the person's starred tasks in a new order over the API and MCP (plan 37.2).
 */
#[Operation(
    name: 'tasks.starred.reorder',
    method: 'POST',
    path: '/api/v1/tasks/starred/reorder',
    tool: 'foresi_reorder_starred_tasks',
    scope: 'tasks:write',
    title: 'Reorder My priorities',
    description: 'Puts the tasks of My priorities of the person of the token in the order given. Name every task the shortlist shows once (foresi_search_tasks with starred true and sort my_order lists them); otherwise it is refused (star_order).',
    response: 'StarredTaskOrder',
)]
final readonly class ReorderStarredTasksInput
{
    /**
     * @param list<mixed> $taskIds
     */
    public function __construct(
        #[Field('The ids of all the tasks of My priorities, in their new order.', items: 'string')]
        public array $taskIds,
    ) {
    }
}
