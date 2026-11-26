<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * The Ui slots of a task's page, filled by the modules that keep something of
 * a task, such as the meeting it was agreed at. A provider answers the slot
 * with Foresi\Ui\Contract\Slot\SlotItem; a task without them shows none, so
 * Work renders the same whether those modules are installed or not. Lower
 * weights come first.
 *
 * The context:
 *  - "taskId" (string): the task's id;
 *  - "projectId" (string): the id of the task's project;
 *  - "return" (string): the local address of the task's page, to come back to after a dialog.
 *
 * HEADER_FACTS: short facts under the page header, such as the meeting the
 * task came from. The item's template is included with its data and the
 * context; an item without a template is left out.
 *
 * HEADER_ACTIONS: the task's main action in the page header, before a new
 * subtask and shown on every screen, such as the timer. The item's template is
 * included with its data and the context; an item without a template is left
 * out. Only the people of the organisation get this slot.
 *
 * SECTIONS: sections of the main column under the subtasks, before the
 * discussion, such as the time logged on the task. The item's template is
 * included with its data and the context; an item without a template is left
 * out. Only the people of the organisation get this slot.
 */
final class TaskSlots
{
    public const string HEADER_FACTS = 'work.task.header_facts';

    public const string HEADER_ACTIONS = 'work.task.header_actions';

    public const string SECTIONS = 'work.task.sections';

    private function __construct()
    {
    }
}
