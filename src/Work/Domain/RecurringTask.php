<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditExcluded;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Shared\Domain\Schedule\RecurringSchedule;

/**
 * A task that comes back on a schedule, such as a weekly retainer slot: a
 * pattern under a project, not a task. On each date of its schedule the daily
 * run creates an ordinary task of the project from it (an occurrence), which
 * links back here; lists, time, budgets and invoicing treat that task as any
 * other. The trigger is the calendar, as Linear's recurring issues and ClickUp's
 * "on schedule": a new task comes whether or not the last one is done, so each
 * occurrence is its own slot and its time stays in its period.
 *
 * It lives as a recurring invoice does: active, paused or ended. nextOccurrence
 * is the date of the next task; the task is created createDaysBefore days
 * earlier and is due dueDaysAfter days after its date, or at the end of its
 * period when that is null. createdCount counts the tasks really created, so an
 * end after a count ignores the dates skipped. A paused one creates nothing and
 * skips what falls in the pause; one date can be skipped on its own, as a
 * planned item's. Editing it changes the tasks still to come, never the ones
 * created. Create and change it through ManageRecurringTasks.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recurring_task')]
#[ORM\Index(name: 'idx_recurring_task_due', columns: ['state', 'next_occurrence'])]
#[ORM\Index(name: 'idx_recurring_task_project', columns: ['project_id'])]
#[ORM\Index(name: 'idx_recurring_task_section', columns: ['section_id'])]
#[ORM\Index(name: 'idx_recurring_task_responsible', columns: ['responsible_id'])]
class RecurringTask implements Audited
{
    /** At most a quarter ahead: further is no slot but a plan. */
    public const int MAX_CREATE_DAYS_BEFORE = 90;
    public const int MAX_DUE_DAYS_AFTER = 365;
    public const int MAX_SUBTASKS = 50;
    public const int MAX_FAILURE_CODE_LENGTH = 64;
    /** The most skipped dates a resume names, as a recurring invoice's. */
    public const int MAX_SKIPPED = 100;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    // Goes up with every change, so a run can tell it worked from an older version.
    #[AuditExcluded]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'string', length: Task::MAX_TITLE_LENGTH)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', nullable: true, onDelete: 'SET NULL')]
    private ?Section $section;

    #[ORM\ManyToOne(targetEntity: Membership::class)]
    #[ORM\JoinColumn(name: 'responsible_id', nullable: true, onDelete: 'SET NULL')]
    private ?Membership $responsible;

    #[ORM\Column(type: 'string', length: 16, enumType: Priority::class)]
    private Priority $priority;

    #[ORM\Column(type: 'boolean')]
    private bool $private;

    #[ORM\Column(name: 'estimate_minutes', type: 'integer', nullable: true)]
    private ?int $estimateMinutes;

    /** @var list<string> */
    #[ORM\Column(name: 'label_ids', type: 'json')]
    private array $labelIds;

    /** @var list<string> membership ids */
    #[ORM\Column(name: 'watcher_ids', type: 'json')]
    private array $watcherIds;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'custom_fields', type: 'json')]
    private array $customFields;

    /** @var list<string> the titles of the subtasks */
    #[ORM\Column(type: 'json')]
    private array $subtasks;

    #[ORM\Column(name: 'first_on', type: 'date_immutable')]
    private \DateTimeImmutable $firstOn;

    #[ORM\Column(name: 'recurrence_unit', type: 'string', length: 8, enumType: RecurrenceUnit::class)]
    private RecurrenceUnit $unit;

    #[ORM\Column(name: 'recurrence_every', type: 'smallint')]
    private int $every;

    #[ORM\Column(name: 'on_last_day_of_month', type: 'boolean')]
    private bool $onLastDayOfMonth;

    #[ORM\Column(name: 'recurrence_end', type: 'string', length: 16, enumType: RecurrenceEnd::class)]
    private RecurrenceEnd $end;

    #[ORM\Column(name: 'end_on', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endOn;

    #[ORM\Column(name: 'end_after', type: 'smallint', nullable: true)]
    private ?int $endAfter;

    #[ORM\Column(name: 'create_days_before', type: 'smallint')]
    private int $createDaysBefore;

    #[ORM\Column(name: 'due_days_after', type: 'smallint', nullable: true)]
    private ?int $dueDaysAfter;

    #[ORM\Column(type: 'string', length: 16, enumType: RecurringTaskState::class)]
    private RecurringTaskState $state = RecurringTaskState::Active;

    #[ORM\Column(name: 'next_occurrence', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextOccurrence;

    #[ORM\Column(name: 'last_occurrence', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastOccurrence = null;

    #[ORM\Column(name: 'created_count', type: 'integer')]
    private int $createdCount = 0;

    /** @var list<string> the skipped dates, Y-m-d, in order */
    #[ORM\Column(name: 'skipped_on', type: 'json')]
    private array $skippedOn = [];

    #[ORM\Column(name: 'paused_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[AuditExcluded]
    #[ORM\Column(name: 'last_failure_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFailureAt = null;

    #[ORM\Column(name: 'last_failure_code', type: 'string', length: self::MAX_FAILURE_CODE_LENGTH, nullable: true)]
    private ?string $lastFailureCode = null;

    /**
     * @param string $owner the user who created it: each task is created in their name
     *
     * @throws \DomainException when the template does not hold together
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Project::class)]
        #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Project $project,
        RecurringTaskTemplate $template,
        #[ORM\Column(name: 'owner_id', type: 'guid')]
        private readonly string $owner,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->firstOn = $template->recurrence->firstIssueOn();
        $this->endOn = $template->recurrence->endOn();
        $this->apply($template);
        $this->nextOccurrence = $this->following($template->recurrence->firstIssueOn())
            ?? throw new \LogicException('A recurrence always allows its first date.');
    }

    /**
     * The tasks created stay as they are; the next date follows the new schedule,
     * never one already created and never one the run has still to catch up with.
     *
     * @throws \DomainException once ended, or when the new schedule leaves no date
     */
    public function change(RecurringTaskTemplate $template, \DateTimeImmutable $today): void
    {
        $this->refuseEnded();
        $previous = $this->recurrence();
        $this->apply($template);
        $next = $this->following($this->resumeFrom($today));
        if (!$next instanceof \DateTimeImmutable) {
            $this->apply(new RecurringTaskTemplate($template->title, $template->description, $template->section, $template->responsible, $template->priority, $template->private, $template->estimateMinutes, $template->labelIds, $template->watcherIds, $template->customFields, $template->subtasks, $previous, $template->createDaysBefore, $template->dueDaysAfter));

            throw new \DomainException('The schedule leaves no date to create a task on.');
        }
        $this->nextOccurrence = $this->keepDay($this->nextOccurrence, $next);
        ++$this->version;
    }

    /**
     * Whether the task of the next date is to be created by $today.
     */
    public function isDue(\DateTimeImmutable $today): bool
    {
        return RecurringTaskState::Active === $this->state
            && $this->nextOccurrence instanceof \DateTimeImmutable
            && $this->day($this->createOn($this->nextOccurrence)) <= $this->day($today);
    }

    /**
     * The day the task of $occurrence is created.
     */
    public function createOn(\DateTimeImmutable $occurrence): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->day($occurrence))->modify(\sprintf('-%d days', $this->createDaysBefore));
    }

    /**
     * The day the task of $occurrence is due: its offset after it, or the last day of its period.
     */
    public function dueOn(\DateTimeImmutable $occurrence): \DateTimeImmutable
    {
        $day = new \DateTimeImmutable($this->day($occurrence));

        return null === $this->dueDaysAfter
            ? new \DateTimeImmutable(RecurringSchedule::periodEnd($this->recurrence(), $day)->format('Y-m-d'))
            : $day->modify(\sprintf('+%d days', $this->dueDaysAfter));
    }

    /**
     * Records that the task of $occurrence was created: the count goes up and the
     * next date follows, or it ends when its end rule allows no more.
     *
     * @throws \DomainException unless it is active and $occurrence is its next date
     */
    public function advance(\DateTimeImmutable $occurrence): void
    {
        if (RecurringTaskState::Active !== $this->state) {
            throw new \DomainException('Only an active recurring task creates tasks.');
        }
        if (!$this->nextOccurrence instanceof \DateTimeImmutable || $this->day($occurrence) !== $this->day($this->nextOccurrence)) {
            throw new \DomainException('A recurring task creates its next date only.');
        }
        ++$this->createdCount;
        $this->lastOccurrence = $this->nextOccurrence;
        $this->nextOccurrence = $this->following($this->nextOccurrence->modify('+1 day'));
        if (!$this->nextOccurrence instanceof \DateTimeImmutable) {
            $this->state = RecurringTaskState::Ended;
            // The day of its last task: the end rule, not a person, ended it.
            $this->endedAt = $this->lastOccurrence;
        }
        ++$this->version;
    }

    /**
     * Leaves out the task of one date, as a planned item skips one occurrence.
     * Skipping it twice changes nothing; a skipped date does not count to an end
     * after a count.
     *
     * @throws \DomainException once ended, for a date whose task is created already, or a day the schedule does not fall on
     */
    public function skip(\DateTimeImmutable $on): void
    {
        $this->refuseEnded();
        $day = $this->day($on);
        $open = RecurringSchedule::open($this->recurrence());
        if ((RecurringSchedule::next($open, 0, $on, 1)[0] ?? null)?->format('Y-m-d') !== $day) {
            throw new \DomainException('The recurring task does not fall on this day.');
        }
        if (!$this->nextOccurrence instanceof \DateTimeImmutable || $day < $this->day($this->nextOccurrence)) {
            throw new \DomainException('The task of this day is created already, or its date passed.');
        }
        if (\in_array($day, $this->skippedOn, true)) {
            return;
        }
        $this->skippedOn[] = $day;
        sort($this->skippedOn);
        if ($day === $this->day($this->nextOccurrence)) {
            $this->nextOccurrence = $this->following($this->nextOccurrence->modify('+1 day'));
            if (!$this->nextOccurrence instanceof \DateTimeImmutable) {
                $this->state = RecurringTaskState::Ended;
                $this->endedAt = new \DateTimeImmutable($day);
                $this->pausedAt = null;
            }
        }
        ++$this->version;
    }

    /**
     * Repeatable: the first moment stays.
     *
     * @throws \DomainException once ended
     */
    public function pause(\DateTimeImmutable $at): void
    {
        $this->refuseEnded();
        if (RecurringTaskState::Paused === $this->state) {
            return;
        }
        $this->state = RecurringTaskState::Paused;
        $this->pausedAt = $at;
        ++$this->version;
    }

    /**
     * Starts again from the first date on or after $today; what fell in the pause
     * is not created. Resuming an active one does nothing.
     *
     * @return list<\DateTimeImmutable> the dates skipped, oldest first, at most MAX_SKIPPED
     *
     * @throws \DomainException once ended
     */
    public function resume(\DateTimeImmutable $today): array
    {
        $this->refuseEnded();
        if (RecurringTaskState::Paused !== $this->state) {
            return [];
        }
        $day = $this->day($today);
        $skipped = [];
        if ($this->nextOccurrence instanceof \DateTimeImmutable && $this->day($this->nextOccurrence) < $day) {
            $recurrence = $this->recurrence();
            foreach (RecurringSchedule::next(RecurringSchedule::open($recurrence), 0, $this->nextOccurrence, self::MAX_SKIPPED) as $date) {
                if ($date->format('Y-m-d') >= $day || RecurringSchedule::isOver($recurrence, $this->createdCount, $date)) {
                    break;
                }
                if (!\in_array($date->format('Y-m-d'), $this->skippedOn, true)) {
                    $skipped[] = $date;
                }
            }
        }
        $this->nextOccurrence = $this->following($this->resumeFrom($today));
        $this->pausedAt = null;
        if ($this->nextOccurrence instanceof \DateTimeImmutable) {
            $this->state = RecurringTaskState::Active;
        } else {
            $this->state = RecurringTaskState::Ended;
            $this->endedAt = $today;
        }
        ++$this->version;

        return $skipped;
    }

    /**
     * For good: an ended recurring task is never resumed. Repeatable.
     */
    public function end(\DateTimeImmutable $at): void
    {
        if (RecurringTaskState::Ended === $this->state) {
            return;
        }
        $this->state = RecurringTaskState::Ended;
        $this->endedAt = $at;
        $this->pausedAt = null;
        $this->nextOccurrence = null;
        ++$this->version;
    }

    /**
     * Up to $count next dates, skipped ones left out, as the end rule allows.
     *
     * @return list<\DateTimeImmutable>
     */
    public function upcoming(int $count): array
    {
        $dates = [];
        $next = $this->nextOccurrence;
        $created = $this->createdCount;
        while ($next instanceof \DateTimeImmutable && \count($dates) < $count) {
            $dates[] = $next;
            ++$created;
            $next = $this->following($next->modify('+1 day'), $created);
        }

        return $dates;
    }

    /**
     * The run could not create the task; the next date stays, so the next run tries again.
     *
     * @throws \DomainException for an empty or overlong code
     */
    public function recordFailure(\DateTimeImmutable $at, string $code): void
    {
        if ('' === $code || \strlen($code) > self::MAX_FAILURE_CODE_LENGTH) {
            throw new \DomainException('A failure has a code of 1 to 64 characters.');
        }
        $this->lastFailureAt = $at;
        $this->lastFailureCode = $code;
    }

    public function clearFailure(): void
    {
        $this->lastFailureAt = null;
        $this->lastFailureCode = null;
    }

    public function recurrence(): Recurrence
    {
        return new Recurrence($this->firstOn, $this->unit, $this->every, $this->onLastDayOfMonth, $this->end, $this->endOn, $this->endAfter);
    }

    public function template(): RecurringTaskTemplate
    {
        return new RecurringTaskTemplate($this->title, $this->description, $this->section, $this->responsible, $this->priority, $this->private, $this->estimateMinutes, $this->labelIds, $this->watcherIds, $this->customFields, $this->subtasks, $this->recurrence(), $this->createDaysBefore, $this->dueDaysAfter);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function section(): ?Section
    {
        return $this->section;
    }

    public function responsible(): ?Membership
    {
        return $this->responsible;
    }

    public function priority(): Priority
    {
        return $this->priority;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function estimateMinutes(): ?int
    {
        return $this->estimateMinutes;
    }

    /**
     * @return list<string>
     */
    public function labelIds(): array
    {
        return $this->labelIds;
    }

    /**
     * @return list<string>
     */
    public function watcherIds(): array
    {
        return $this->watcherIds;
    }

    /**
     * @return array<string, mixed>
     */
    public function customFields(): array
    {
        return $this->customFields;
    }

    /**
     * @return list<string>
     */
    public function subtasks(): array
    {
        return $this->subtasks;
    }

    public function createDaysBefore(): int
    {
        return $this->createDaysBefore;
    }

    public function dueDaysAfter(): ?int
    {
        return $this->dueDaysAfter;
    }

    public function state(): RecurringTaskState
    {
        return $this->state;
    }

    public function nextOccurrence(): ?\DateTimeImmutable
    {
        return $this->nextOccurrence;
    }

    public function lastOccurrence(): ?\DateTimeImmutable
    {
        return $this->lastOccurrence;
    }

    public function createdCount(): int
    {
        return $this->createdCount;
    }

    /**
     * @return list<string> Y-m-d, in order
     */
    public function skippedOn(): array
    {
        return $this->skippedOn;
    }

    public function pausedAt(): ?\DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function endedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function lastFailureAt(): ?\DateTimeImmutable
    {
        return $this->lastFailureAt;
    }

    public function lastFailureCode(): ?string
    {
        return $this->lastFailureCode;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @throws \DomainException
     */
    private function apply(RecurringTaskTemplate $template): void
    {
        if ($template->section instanceof Section && $template->section->project()->id() !== $this->project->id()) {
            throw new \DomainException('The section belongs to another project.');
        }
        if ($template->createDaysBefore < 0 || $template->createDaysBefore > self::MAX_CREATE_DAYS_BEFORE) {
            throw new \DomainException(\sprintf('A task is created 0 to %d days before its date.', self::MAX_CREATE_DAYS_BEFORE));
        }
        if (null !== $template->dueDaysAfter && ($template->dueDaysAfter < 0 || $template->dueDaysAfter > self::MAX_DUE_DAYS_AFTER)) {
            throw new \DomainException(\sprintf('A task is due 0 to %d days after its date.', self::MAX_DUE_DAYS_AFTER));
        }
        if (null !== $template->estimateMinutes && ($template->estimateMinutes < 1 || $template->estimateMinutes > Task::MAX_ESTIMATE_MINUTES)) {
            throw new \DomainException(\sprintf('An estimate is between a minute and %d minutes.', Task::MAX_ESTIMATE_MINUTES));
        }
        if (\count($template->subtasks) > self::MAX_SUBTASKS) {
            throw new \DomainException(\sprintf('A recurring task starts with at most %d subtasks.', self::MAX_SUBTASKS));
        }
        $subtasks = [];
        foreach ($template->subtasks as $subtask) {
            $subtasks[] = Name::of($subtask, Task::MAX_TITLE_LENGTH, 'A subtask');
        }
        $recurrence = $template->recurrence;
        $this->title = Name::of($template->title, Task::MAX_TITLE_LENGTH, 'A task');
        $this->description = $template->description;
        $this->section = $template->section;
        $this->responsible = $template->responsible;
        $this->priority = $template->priority;
        $this->private = $template->private;
        $this->estimateMinutes = $template->estimateMinutes;
        $this->labelIds = array_values(array_unique($template->labelIds));
        $this->watcherIds = array_values(array_unique($template->watcherIds));
        $this->customFields = $template->customFields;
        $this->subtasks = $subtasks;
        // A new object for the same day would count as a change and reach the audit log.
        $this->firstOn = $this->keepDay($this->firstOn, $recurrence->firstIssueOn());
        $this->unit = $recurrence->unit();
        $this->every = $recurrence->every();
        $this->onLastDayOfMonth = $recurrence->onLastDayOfMonth();
        $this->end = $recurrence->end();
        $endOn = $recurrence->endOn();
        $this->endOn = $endOn instanceof \DateTimeImmutable ? $this->keepDay($this->endOn, $endOn) : null;
        $this->endAfter = $recurrence->endAfter();
        $this->createDaysBefore = $template->createDaysBefore;
        $this->dueDaysAfter = $template->dueDaysAfter;
    }

    /**
     * Where a resumed or changed schedule goes on from: $today, but never before a
     * date the run has still to catch up with when active, nor on a date created already.
     */
    private function resumeFrom(\DateTimeImmutable $today): \DateTimeImmutable
    {
        $from = $this->day($today);
        if (RecurringTaskState::Active === $this->state && $this->nextOccurrence instanceof \DateTimeImmutable && $this->day($this->nextOccurrence) < $from) {
            $from = $this->day($this->nextOccurrence);
        }
        if ($this->lastOccurrence instanceof \DateTimeImmutable && $this->day($this->lastOccurrence->modify('+1 day')) > $from) {
            $from = $this->day($this->lastOccurrence->modify('+1 day'));
        }

        return new \DateTimeImmutable($from);
    }

    /**
     * The first date on or after $from that is not skipped and that the end rule
     * allows once $created tasks exist (createdCount when not given).
     */
    private function following(\DateTimeImmutable $from, ?int $created = null): ?\DateTimeImmutable
    {
        $recurrence = $this->recurrence();
        $created ??= $this->createdCount;
        $date = RecurringSchedule::firstFrom($recurrence, $created, $from);
        for ($tries = 0; $date instanceof \DateTimeImmutable && \in_array($this->day($date), $this->skippedOn, true) && $tries <= \count($this->skippedOn); ++$tries) {
            $date = RecurringSchedule::firstFrom($recurrence, $created, $date->modify('+1 day'));
        }

        return $date;
    }

    private function keepDay(?\DateTimeImmutable $stored, \DateTimeImmutable $given): \DateTimeImmutable
    {
        return $stored instanceof \DateTimeImmutable && $this->day($stored) === $this->day($given) ? $stored : $given;
    }

    private function day(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    private function refuseEnded(): void
    {
        if (RecurringTaskState::Ended === $this->state) {
            throw new \DomainException('An ended recurring task stays ended.');
        }
    }
}
