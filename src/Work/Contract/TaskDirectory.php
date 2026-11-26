<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * The tasks as other modules read them, without Work's own classes: the pills
 * of a meeting's action items, the open tasks of the meeting before. It reads
 * as the person signed in, so a task they do not see is not there, as
 * everywhere in Work.
 */
interface TaskDirectory
{
    /**
     * In one query, whatever the number of ids.
     *
     * @param list<string> $ids
     *
     * @return array<string, TaskSummary> by id, the tasks in the trash too, flagged; an unknown id or one of a task the person does not see is left out
     */
    public function summaries(array $ids): array;
}
