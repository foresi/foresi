<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\CommentMention;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;

/**
 * The comments on tasks of the active tenant with the people they mention.
 */
interface Comments
{
    public function comment(string $id): ?TaskComment;

    /**
     * @return list<TaskComment> oldest first, deleted ones included
     */
    public function of(Task $task): array;

    /**
     * @return list<CommentMention>
     */
    public function mentions(TaskComment $comment): array;

    public function add(TaskComment|CommentMention $item): void;

    public function remove(CommentMention $mention): void;
}
