<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Timesheet\Domain\TimeEntry;
use Foresi\Work\Application\Projects;
use Foresi\Work\Application\VisibleWork;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Application\WorkUnit;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Domain\Duration;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Task;
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
 * or deleted, not edited. Deleting puts an entry in the trash, from which the
 * same people restore it, once its task is out of the trash (ClickUp).
 */
final readonly class ManageTime
{
    public function __construct(
        private ProjectTimeSettings $settings,
        private TimeEntries $entries,
        private Projects $projects,
        private VisibleWork $work,
        private WorkVisibility $visibility,
        private People $people,
        private OwnerCheck $owner,
        private AuditActor $actor,
        private ClockInterface $clock,
        private InstanceClock $instanceClock,
        private WorkUnit $unit,
    ) {
    }

    /**
     * Time worked on the day: the minutes, or the start and the end it took.
     *
     * @param ?bool $billable null for the project's default
     *
     * @throws TimeRefused|WorkRefused
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
        $entry = TimeEntry::logged($project, $task, $me, $workedOn, $minutes, $startedAt, $endedAt, $this->note($note), $billable ?? $this->settings->billsByDefault($project), $me->user()->id(), $this->clock->now());

        return $this->unit->run(function () use ($entry): TimeEntry {
            $this->entries->add($entry);

            return $entry;
        });
    }

    /**
     * Changes everything an entry says but its project and its person; the
     * task may change within the project.
     *
     * @throws TimeRefused|WorkRefused
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
            throw new TimeRefused('timer_running');
        }
        [, $task] = $this->target($entry->project()->id(), $taskId);
        $minutes = $this->minutes($minutes, $startedAt, $endedAt);
        $note = $this->note($note);
        $this->unit->run(static fn () => $entry->change($task, $workedOn, $minutes, $startedAt, $endedAt, $note, $billable));
    }

    /**
     * Puts the entry in the trash; a running timer is stopped first.
     *
     * @throws TimeRefused|WorkRefused
     */
    public function delete(string $entryId): TimeEntry
    {
        $entry = $this->editable($entryId);
        $userId = $this->me()->user()->id();

        return $this->unit->run(function () use ($entry, $userId): TimeEntry {
            $entry->trash($userId, $this->clock->now());

            return $entry;
        });
    }

    /**
     * Takes the entry out of the trash, for whom may delete it; its task, when
     * in the trash itself, comes out first.
     *
     * @throws TimeRefused|WorkRefused
     */
    public function restore(string $entryId): TimeEntry
    {
        $entry = $this->entries->entry($entryId);
        if (!$entry instanceof TimeEntry || !$entry->isDeleted() || !$this->visibility->scope()->seesWorkOn($entry->project(), $entry->task())) {
            throw new TimeRefused('unknown');
        }
        $this->mayChange($entry);
        if (true === $entry->task()?->isDeleted()) {
            throw new TimeRefused('time_task_in_trash');
        }

        return $this->unit->run(static function () use ($entry): TimeEntry {
            $entry->restore();

            return $entry;
        });
    }

    /**
     * A timer from now on the day of the organisation; the running one, if
     * any, is stopped and saved first.
     *
     * @param ?bool $billable null for the project's default
     *
     * @throws TimeRefused|WorkRefused
     */
    public function startTimer(string $projectId, ?string $taskId, string $note = '', ?bool $billable = null): TimeEntry
    {
        [$project, $task] = $this->target($projectId, $taskId);
        $me = $this->me();
        $now = $this->clock->now();
        $entry = TimeEntry::started($project, $task, $me, $this->instanceClock->today(), $now, $this->note($note), $billable ?? $this->settings->billsByDefault($project), $me->user()->id());
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
     * @throws TimeRefused|WorkRefused
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
     * The project and the task time goes on, where the person may log it: a
     * task they see, of the project; or the project, open to them as a whole.
     *
     * @return array{Project, ?Task}
     *
     * @throws TimeRefused|WorkRefused
     */
    private function target(string $projectId, ?string $taskId): array
    {
        // Time on a task needs only the task to be seen, not the whole project.
        $task = null === $taskId ? null : $this->work->task($taskId);
        if ($task instanceof Task && $task->project()->id() !== $projectId) {
            throw new TimeRefused('time_task');
        }
        $project = $task?->project() ?? $this->work->project($projectId);
        if ($project->isArchived()) {
            throw new TimeRefused('archived');
        }
        if (!$this->visibility->canRecordWork($project, $task)) {
            throw new TimeRefused('not_allowed');
        }

        return [$project, $task];
    }

    /**
     * An entry the person sees and may change: their own, or anyone's for an
     * owner, not invoiced and not in the trash.
     *
     * @throws TimeRefused|WorkRefused
     */
    private function editable(string $entryId): TimeEntry
    {
        $entry = $this->entries->entry($entryId);
        $task = $entry?->task();
        if (!$entry instanceof TimeEntry || $entry->isDeleted() || true === $task?->isDeleted() || !$this->visibility->scope()->seesWorkOn($entry->project(), $task)) {
            throw new TimeRefused('unknown');
        }
        $this->mayChange($entry);
        if ($entry->isInvoiced()) {
            throw new TimeRefused('time_invoiced');
        }

        return $entry;
    }

    /**
     * The person's own entry, or anyone's for an owner.
     *
     * @throws TimeRefused|WorkRefused
     */
    private function mayChange(TimeEntry $entry): void
    {
        if ($entry->membership()->id() !== $this->me()->id() && !$this->owner->isOwner()) {
            throw new TimeRefused('time_not_yours');
        }
    }

    /**
     * The active membership of the signed-in person.
     *
     * @throws TimeRefused|WorkRefused
     */
    private function me(): Membership
    {
        $userId = $this->actor->userId() ?? throw new TimeRefused('not_allowed');
        $membership = $this->people->membershipOf($userId);
        if (!$membership instanceof Membership || !$membership->isActive()) {
            throw new TimeRefused('not_allowed');
        }

        return $membership;
    }

    /**
     * The minutes given, or those from the start to the end: one of the two.
     *
     * @throws TimeRefused|WorkRefused when neither or both are given, or the time is nothing or more than a day
     */
    private function minutes(?int $minutes, ?\DateTimeImmutable $startedAt, ?\DateTimeImmutable $endedAt): int
    {
        try {
            return match (true) {
                null !== $minutes && !$startedAt instanceof \DateTimeImmutable && !$endedAt instanceof \DateTimeImmutable => Duration::ofMinutes($minutes)->minutes,
                null === $minutes && $startedAt instanceof \DateTimeImmutable && $endedAt instanceof \DateTimeImmutable => Duration::between($startedAt, $endedAt)->minutes,
                default => throw new TimeRefused('duration'),
            };
        } catch (TimeRefused|WorkRefused $refused) {
            throw $refused;
        } catch (\DomainException) {
            throw new TimeRefused('duration');
        }
    }

    /**
     * @throws TimeRefused when the note is too long
     */
    private function note(string $note): string
    {
        try {
            return TimeEntry::checkedNote($note);
        } catch (\DomainException) {
            throw new TimeRefused('time_note');
        }
    }
}
