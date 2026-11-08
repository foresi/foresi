<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * The files attached to tasks and their comments, as the purge of the trash
 * removes them for good.
 */
interface TaskFiles
{
    /**
     * Removes the attachments of these tasks and comments, records and files.
     *
     * @param list<string> $taskIds
     * @param list<string> $commentIds
     */
    public function purge(array $taskIds, array $commentIds): void;
}
