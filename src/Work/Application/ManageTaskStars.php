<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Work\Domain\TaskStar;
use Psr\Clock\ClockInterface;

/**
 * My priorities (plan 37.2): the person stars a task they see, takes the star off and puts
 * the starred tasks in their own order, as Freelo's "My priorities". The list is the
 * person's alone; nobody else sees whose star a task has.
 *
 * A new star goes to the end, so the order the person built stays (Martin, 2026-11-26).
 * Starring twice is starring once and keeps the place. A finished task is not starred: it
 * leaves the list when it is finished (ManageTasks). A star on a task the person no
 * longer sees, or one in the trash, keeps its place out of sight: the order and the moves
 * are about the tasks the list shows.
 */
final readonly class ManageTaskStars
{
    public function __construct(
        private VisibleWork $work,
        private WorkVisibility $visibility,
        private TaskStars $stars,
        private People $people,
        private AuditActor $actor,
        private ClockInterface $clock,
        private WorkUnit $unit,
    ) {
    }

    /**
     * Stars the task, at the end of the list or before the starred task named.
     *
     * @throws WorkRefused
     */
    public function star(string $taskId, ?string $beforeTaskId = null): void
    {
        $task = $this->work->task($taskId);
        if ($task->status()->type()->isFinished()) {
            throw new WorkRefused('star_finished');
        }
        $me = $this->me();
        $this->unit->run(function () use ($task, $me, $beforeTaskId): void {
            $all = $this->stars->of($me);
            $star = $this->stars->star($task, $me);
            if (!$star instanceof TaskStar) {
                $star = new TaskStar($task, $me, [] === $all ? 0 : max(array_map(static fn (TaskStar $one): int => $one->position(), $all)) + 1, $this->clock->now());
                $this->stars->add($star);
            }
            if (null !== $beforeTaskId) {
                $this->place($all, $star, $beforeTaskId);
            }
        });
    }

    /**
     * Takes the star off; a task with none stays as it is.
     *
     * @throws WorkRefused
     */
    public function unstar(string $taskId): void
    {
        $task = $this->work->task($taskId);
        $me = $this->me();
        $this->unit->run(function () use ($task, $me): void {
            $star = $this->stars->star($task, $me);
            if ($star instanceof TaskStar) {
                $this->stars->remove($star);
            }
        });
    }

    /**
     * Moves a starred task before another of the list, or to its end.
     *
     * @throws WorkRefused unknown when the task has no star of the person
     */
    public function move(string $taskId, ?string $beforeTaskId): void
    {
        $task = $this->work->task($taskId);
        $me = $this->me();
        $this->unit->run(function () use ($task, $me, $beforeTaskId): void {
            $star = $this->stars->star($task, $me) ?? throw new WorkRefused('unknown');
            $this->place($this->stars->of($me), $star, $beforeTaskId);
        });
    }

    /**
     * Puts the tasks of the list in the order given, which names each of them once; the
     * stars out of sight follow them in their order.
     *
     * @param list<string> $taskIds
     *
     * @throws WorkRefused star_order
     */
    public function reorder(array $taskIds): void
    {
        $me = $this->me();
        $this->unit->run(function () use ($me, $taskIds): void {
            $all = $this->stars->of($me);
            $listed = [];
            foreach ($all as $star) {
                if ($this->listed($star)) {
                    $listed[$star->task()->id()] = $star;
                }
            }
            $given = array_unique($taskIds);
            if (\count($given) !== \count($taskIds) || \count($given) !== \count($listed) || [] !== array_diff($given, array_keys($listed))) {
                throw new WorkRefused('star_order');
            }
            $hidden = array_filter($all, fn (TaskStar $star): bool => !$this->listed($star));
            Positions::renumber([...array_map(static fn (string $id): TaskStar => $listed[$id], $given), ...$hidden]);
        });
    }

    /**
     * @param list<TaskStar> $all the person's stars in order
     *
     * @throws WorkRefused unknown when the task to go before has no star the list shows
     */
    private function place(array $all, TaskStar $star, ?string $beforeTaskId): void
    {
        $others = array_values(array_filter($all, static fn (TaskStar $one): bool => $one->id() !== $star->id()));
        $index = \count($others);
        if (null !== $beforeTaskId) {
            $index = null;
            foreach ($others as $at => $one) {
                if ($one->task()->id() === $beforeTaskId && $this->listed($one)) {
                    $index = $at;
                }
            }
            if (null === $index) {
                throw new WorkRefused('unknown');
            }
        }
        Positions::place($others, $star, $index);
    }

    /**
     * Whether My priorities shows the star's task: one out of the trash, of an active
     * project, not finished, that the person sees.
     */
    private function listed(TaskStar $star): bool
    {
        $task = $star->task();

        return !$task->isDeleted() && !$task->project()->isArchived() && !$task->status()->type()->isFinished() && $this->visibility->canSeeTask($task);
    }

    /**
     * @throws WorkRefused unknown for one with no active membership
     */
    private function me(): Membership
    {
        $userId = $this->actor->userId();
        $membership = null === $userId ? null : $this->people->membershipOf($userId);

        return $membership instanceof Membership && $membership->isActive() ? $membership : throw new WorkRefused('unknown');
    }
}
