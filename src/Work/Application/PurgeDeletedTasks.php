<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;

/**
 * Removes for good the tasks that have been in the trash for more than 30
 * days, each with its subtasks, comments and the people they mention,
 * watchers, labels, shares and files. The audit log keeps what they were: it
 * is the record of the organisation, as for invoices (ADR 0013).
 *
 * Each task goes as one step, in one transaction with the records of its
 * files; the files themselves go once it is committed. When the step fails,
 * nothing of the task is gone, files included, and the next run takes it again.
 */
final readonly class PurgeDeletedTasks
{
    public const int DAYS = 30;

    public function __construct(
        private Tasks $tasks,
        private Comments $comments,
        private TaskFiles $files,
        private Projects $projects,
        private WorkShares $shares,
        private WorkUnit $unit,
    ) {
    }

    /**
     * @return int how many tasks were removed, subtasks included
     */
    public function run(\DateTimeImmutable $now): int
    {
        $expired = $this->tasks->trashedBefore($now->modify(sprintf('-%d days', self::DAYS)));
        $ids = array_flip(array_map(static fn (Task $task): string => $task->id(), $expired));
        $removed = 0;
        foreach ($expired as $task) {
            // A subtask whose task goes too goes with it.
            $parent = $task->parent();
            if (!$parent instanceof Task || !isset($ids[$parent->id()])) {
                $removed += $this->purge($task);
            }
        }

        return $removed;
    }

    private function purge(Task $task): int
    {
        $tasks = [...$this->tasks->subtasksOf($task), $task];
        $comments = [];
        foreach ($tasks as $one) {
            array_push($comments, ...$this->comments->of($one));
        }

        $files = $this->unit->run(fn (): array => $this->projects->transactional(function () use ($task, $tasks, $comments): array {
            $files = $this->files->forget(
                array_map(static fn (Task $one): string => $one->id(), $tasks),
                array_map(static fn (TaskComment $comment): string => $comment->id(), $comments),
            );
            foreach ($comments as $comment) {
                foreach ($this->comments->mentions($comment) as $mention) {
                    $this->comments->remove($mention);
                }
                $this->comments->remove($comment);
            }
            foreach ($tasks as $one) {
                foreach ([...$this->tasks->watchers($one), ...$this->tasks->labels($one)] as $item) {
                    $this->tasks->remove($item);
                }
                foreach ($this->shares->on($one) as $share) {
                    $this->shares->remove($share);
                }
                if ($one !== $task) {
                    $this->tasks->remove($one);
                }
            }
            // The subtasks are gone before the task they point at.
            $this->projects->flush();
            $this->tasks->remove($task);

            return $files;
        }));
        $this->files->removeFiles($files);

        return \count($tasks);
    }
}
