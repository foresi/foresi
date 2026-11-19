<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\TaskComment;

/**
 * Clears for good the text and the files of the comments deleted more than 30
 * days ago, as tasks leave the trash. The comment stays in its discussion as
 * the note that something was said; the audit log keeps what it said.
 *
 * The records of the files go in the same transaction as the text; the files
 * themselves once it is committed.
 */
final readonly class PurgeDeletedComments
{
    public const int DAYS = PurgeDeletedTasks::DAYS;

    public function __construct(
        private Comments $comments,
        private TaskFiles $files,
        private WorkUnit $unit,
    ) {
    }

    /**
     * @return int how many comments were cleared
     */
    public function run(\DateTimeImmutable $now): int
    {
        $expired = $this->comments->deletedBefore($now->modify(sprintf('-%d days', self::DAYS)));
        if ([] === $expired) {
            return 0;
        }
        $files = $this->unit->run(function () use ($expired): array {
            foreach ($expired as $comment) {
                $comment->purge();
            }

            return $this->files->forget([], array_map(static fn (TaskComment $comment): string => $comment->id(), $expired));
        });
        $this->files->removeFiles($files);

        return \count($expired);
    }
}
