<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Work\Application\View\TaskLists;

/**
 * What the list of all tasks (TaskLists) shows: the conditions of its view and its filter,
 * a text the title or the project's name holds, its sort and what it groups by. The
 * groups lead the order, the sort orders the tasks within each.
 */
final readonly class TaskSearch
{
    /**
     * @param string $sort  one of TaskLists::SORTS, a minus first for backwards
     * @param string $group one of TaskLists::GROUPS
     */
    public function __construct(
        public ViewFilter $filter = new ViewFilter(),
        public string $text = '',
        public string $sort = TaskLists::DEFAULT_SORT,
        public string $group = TaskLists::GROUP_NONE,
    ) {
    }
}
