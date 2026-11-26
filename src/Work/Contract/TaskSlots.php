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
 */
final class TaskSlots
{
    public const string HEADER_FACTS = 'work.task.header_facts';

    private function __construct()
    {
    }
}
