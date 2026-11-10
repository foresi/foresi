<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Tenancy\Application\TenantClock;
use App\Work\Domain\Duration;
use App\Work\Domain\Project;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use Psr\Clock\ClockInterface;

/**
 * Logging time on a task, or on a project without a task, by hand or with a
 * timer. The people of the organisation log their own time where they see the
 * task, or the whole project for time without one; guests never do. An entry
 * is billable as its project says unless said otherwise (Kimai, Toggl).
 *
 * A person runs one timer at a time: starting another saves the running one
 * first (Freelo), in the same step. Whoever runs a timer stops it, even once
 * they no longer see its task.
 *
 * The author edits and deletes their entries, an owner of the organisation
 * anyone's, until an entry is invoiced (Teamwork). A running timer is stopped
 * or deleted, not edited.
 */
final readonly class ManageTime
{
    public function __construct(
        private TimeEntries $entries,
        private Projects $projects,
        private VisibleWork $work,
        private WorkVisibility $visibility,
        private People $people,
        private OwnerCheck $owner,
        private AuditActor $actor,
        private ClockInterface $clock,
        private TenantClock $tenantClock,
        private WorkUnit $unit,
    ) {
    }

    /**
     * Time worked on the day: the minutes, or the start and the end it took.
     *
     * @param ?bool $billable null for the project's default
     *
     * @throws WorkRefused
     */
    public function log(
        string $projectId,
        ?string $taskId,
        \DateTimeImmutable $workedOn,
        ?int $minutes,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $endedAt = null,
        string $note = '',
        ?bool $billable = null,
    ): TimeEntry {
        [$project, $task] = $this->target($projectId, $taskId);
        $me = $this->me();
        $minutes = $this->minutes($minutes, $startedAt, $endedAt);
        $entry = TimeEntry::logged($project, $task, $me, $workedOn, $minutes, $startedAt, $endedAt, $this->note($note), $billable ?? $project->billsTimeByDefault(), $me->user()->id(), $this->clock->now());

        return $this->unit->run(function () use ($entry): TimeEntry {
            $this->entries->add($entry);

            return $entry;
        });
    }

    /**
     * Changes everything an entry says but its project and its person; the
     * task may change within the project.
     *
     * @throws WorkRefused
     */
    public function edit(
        string $entryId,
        ?string $taskId,
        \DateTimeImmutable $workedOn,
        ?int $minutes,
        ?\DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        string $note,
        bool $billable,
    ): void {
        $entry = $this->editable($entryId);
        if ($entry->isRunning()) {
            throw new WorkRefused('timer_running');
        }
        [, $task] = $this->target($entry->project()->id(), $taskId);
        $minutes = $this->minutes($minutes, $startedAt, $endedAt);
        $note = $this->note($note);
        $this->unit->run(static fn () => $entry->change($task, $workedOn, $minutes, $startedAt, $endedAt, $note, $billable));
    }

    /**
     * Deleting a running timer discards it.
     *
     * @throws WorkRefused
     */
    public function delete(string $entryId): void
    {
        $entry = $this->editable($entryId);
        $this->unit->run(fn () => $this->entries->remove($entry));
    }

    /**
     * A timer from now on the day of the organisation; the running one, if
     * any, is stopped and saved first.
     *
     * @param ?bool $billable null for the project's default
     *
     * @throws WorkRefused
     */
    public function startTimer(string $projectId, ?string $taskId, string $note = '', ?bool $billable = null): TimeEntry
    {
        [$project, $task] = $this->target($projectId, $taskId);
        $me = $this->me();
        $now = $this->clock->now();
        $entry = TimeEntry::started($project, $task, $me, $this->tenantClock->today(), $now, $this->note($note), $billable ?? $project->billsTimeByDefault(), $me->user()->id());
        $running = $this->entries->running($me);

        return $this->unit->run(function () use ($entry, $running, $now): TimeEntry {
            if ($running instanceof TimeEntry) {
                $running->stop($now);
                // The running one is saved before the new one, which the unique index of running timers would refuse otherwise.
                $this->projects->flush();
            }
            $this->entries->add($entry);

            return $entry;
        });
    }

    /**
     * Stops the timer of the signed-in person.
     *
     * @return ?TimeEntry the stopped entry, or null when no timer ran
     *
     * @throws WorkRefused
     */
    public function stopTimer(): ?TimeEntry
    {
        $running = $this->entries->running($this->me());
        if (!$running instanceof TimeEntry) {
            return null;
        }

        return $this->unit->run(function () use ($running): TimeEntry {
            $running->stop($this->clock->now());

            return $running;
        });
    }

    /**
     * @param ?int $minutes null for no estimate
     *
     * @throws WorkRefused
     */
    public function setEstimate(string $taskId, ?int $minutes): void
    {
        $task = $this->work->task($taskId);
        if (!$this->visibility->canLogTime($task->project(), $task)) {
            throw new WorkRefused('not_allowed');
        }
        if (null !== $minutes && ($minutes < 1 || $minutes > Task::MAX_ESTIMATE_MINUTES)) {
            throw new WorkRefused('estimate');
        }
        $this->unit->run(static fn () => $task->estimate($minutes));
    }

    /**
     * @throws WorkRefused
     */
    public function setProjectBillableDefault(string $projectId, bool $billable): void
    {
        $project = $this->work->managedProject($projectId);
        $this->unit->run(static fn () => $project->billTimeByDefault($billable));
    }

    /**
     * The project and the task time goes on, where the person may log it: a
     * task they see, of the project; or the project, open to them as a whole.
     *
     * @return array{Project, ?Task}
     *
     * @throws WorkRefused
     */
    private function target(string $projectId, ?string $taskId): array
    {
        // Time on a task needs only the task to be seen, not the whole project.
        $task = null === $taskId ? null : $this->work->task($taskId);
        if ($task instanceof Task && $task->project()->id() !== $projectId) {
            throw new WorkRefused('time_task');
        }
        $project = $task?->project() ?? $this->work->project($projectId);
        if ($project->isArchived()) {
            throw new WorkRefused('archived');
        }
        if (!$this->visibility->canLogTime($project, $task)) {
            throw new WorkRefused('not_allowed');
        }

        return [$project, $task];
    }

    /**
     * An entry the person sees and may change: their own, or anyone's for an
     * owner, and not invoiced.
     *
     * @throws WorkRefused
     */
    private function editable(string $entryId): TimeEntry
    {
        $entry = $this->entries->entry($entryId);
        $task = $entry?->task();
        if (!$entry instanceof TimeEntry || true === $task?->isDeleted() || !$this->visibility->scope()->seesTime($entry->project(), $task)) {
            throw new WorkRefused('unknown');
        }
        if ($entry->membership()->id() !== $this->me()->id() && !$this->owner->isOwner()) {
            throw new WorkRefused('time_not_yours');
        }
        if ($entry->isInvoiced()) {
            throw new WorkRefused('time_invoiced');
        }

        return $entry;
    }

    /**
     * The active membership of the signed-in person.
     *
     * @throws WorkRefused
     */
    private function me(): Membership
    {
        $userId = $this->actor->userId() ?? throw new WorkRefused('not_allowed');
        $membership = $this->people->membershipOf($userId);
        if (!$membership instanceof Membership || !$membership->isActive()) {
            throw new WorkRefused('not_allowed');
        }

        return $membership;
    }

    /**
     * The minutes given, or those from the start to the end: one of the two.
     *
     * @throws WorkRefused when neither or both are given, or the time is nothing or more than a day
     */
    private function minutes(?int $minutes, ?\DateTimeImmutable $startedAt, ?\DateTimeImmutable $endedAt): int
    {
        try {
            return match (true) {
                null !== $minutes && !$startedAt instanceof \DateTimeImmutable && !$endedAt instanceof \DateTimeImmutable => Duration::ofMinutes($minutes)->minutes,
                null === $minutes && $startedAt instanceof \DateTimeImmutable && $endedAt instanceof \DateTimeImmutable => Duration::between($startedAt, $endedAt)->minutes,
                default => throw new WorkRefused('duration'),
            };
        } catch (WorkRefused $refused) {
            throw $refused;
        } catch (\DomainException) {
            throw new WorkRefused('duration');
        }
    }

    /**
     * @throws WorkRefused when the note is too long
     */
    private function note(string $note): string
    {
        try {
            return TimeEntry::checkedNote($note);
        } catch (\DomainException) {
            throw new WorkRefused('time_note');
        }
    }
}
