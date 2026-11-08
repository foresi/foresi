<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * The files attached to tasks and their comments, as the purge of the trash
 * removes them for good: the records with the tasks, the files after.
 */
interface TaskFiles
{
    /**
     * Removes the records of the attachments of these tasks and comments,
     * saved with the tasks.
     *
     * @param list<string> $taskIds
     * @param list<string> $commentIds
     *
     * @return list<string> what removeFiles takes once that is committed
     */
    public function forget(array $taskIds, array $commentIds): array;

    /**
     * Removes the files of records already removed; one that cannot be removed is logged and left.
     *
     * @param list<string> $files from forget
     */
    public function removeFiles(array $files): void;
}
