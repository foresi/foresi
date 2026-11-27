<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Domain\Schedule\InvalidRecurrence;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Shared\Domain\Schedule\RecurringSchedule;
use Foresi\Work\Application\Api\CreateRecurringTaskInput;
use Foresi\Work\Application\Api\UpdateRecurringTaskInput;
use Foresi\Work\Contract\Event\RecurringTaskChanged;
use Foresi\Work\Contract\Event\RecurringTaskCreated;
use Foresi\Work\Contract\Event\RecurringTaskEnded;
use Foresi\Work\Contract\Event\RecurringTaskOccurrenceSkipped;
use Foresi\Work\Contract\Event\RecurringTaskPaused;
use Foresi\Work\Contract\Event\RecurringTaskResumed;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\Label;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\RecurringTaskState;
use Foresi\Work\Domain\RecurringTaskTemplate;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\Task;
use Psr\Clock\ClockInterface;

/**
 * Setting recurring tasks up and changing them, as the screens and the API
 * (ADR 0032) do. Only the people of the organisation do it, in a project they
 * see and where they may add tasks; a recurring task is seen with its project.
 * Each change is one step with its event. A change that leaves a task due by
 * today creates it at once, so a weekly slot set up on its Monday is there
 * before the next morning's run.
 */
final readonly class ManageRecurringTasks
{
    /** How many next dates the preview shows by default, as the recurring invoices'. */
    public const int PREVIEW = 5;
    public const int MAX_PREVIEW = 24;

    public function __construct(
        private RecurringTasks $recurring,
        private VisibleWork $work,
        private WorkVisibility $visibility,
        private Labels $labels,
        private People $people,
        private Fields $fields,
        private AuditActor $actor,
        private EventRecorder $events,
        private WorkUnit $unit,
        private InstanceClock $instanceClock,
        private ClockInterface $clock,
        private CreateRecurringTasks $run,
    ) {
    }

    /**
     * @param array<string, mixed>|null $customFields by key, as CustomFieldPayload::read() gives them
     *
     * @throws WorkRefused nothing is stored
     */
    public function create(CreateRecurringTaskInput $input, ?array $customFields = null): RecurringTask
    {
        $this->work->assertManager();
        $project = $this->work->project($input->projectId);
        if ($project->isArchived()) {
            throw new WorkRefused('archived');
        }
        $owner = $this->actor->userId() ?? throw new WorkRefused('not_allowed');
        $template = $this->template($project, new RecurringTaskFields(
            $input->title, $input->description, $input->sectionId, $input->responsibleId, $input->priority, $input->private, $input->estimateMinutes,
            $input->labelIds, $input->watcherIds, $input->subtasks, $input->firstOn, $input->repeat, $input->every, $input->lastDayOfMonth, $input->end,
            $input->endsOn, $input->endsAfter, $input->createDaysBefore, $input->dueDaysAfter, $this->encoded($customFields ?? []),
        ));
        $recurring = $this->unit->run(function () use ($project, $template, $owner): RecurringTask {
            $recurring = WorkRefused::unlessNamed(fn (): RecurringTask => new RecurringTask($project, $template, $owner, $this->clock->now()));
            $this->recurring->add($recurring);
            $this->events->record(new RecurringTaskCreated($recurring->id(), $project->id()));

            return $recurring;
        });
        $this->run->runOne($recurring->id());

        return $this->get($recurring->id());
    }

    /**
     * @param array<string, mixed>|null $customFields by key, as CustomFieldPayload::read() gives them; null to keep them
     *
     * @throws WorkRefused nothing is stored
     */
    public function update(UpdateRecurringTaskInput $input, ?array $customFields = null): RecurringTask
    {
        $recurring = $this->get($input->recurringTaskId);
        $this->refuseEnded($recurring);
        $was = $recurring->template();
        $recurrence = $was->recurrence;
        $end = $input->end instanceof Omitted ? $recurrence->end()->value : $input->end;
        $template = $this->template($recurring->project(), new RecurringTaskFields(
            $input->title instanceof Omitted ? $was->title : $input->title,
            $input->description instanceof Omitted ? $was->description : $input->description,
            $input->sectionId instanceof Omitted ? $was->section?->id() : $input->sectionId,
            $input->responsibleId instanceof Omitted ? $was->responsible?->id() : $input->responsibleId,
            $input->priority instanceof Omitted ? $was->priority->value : $input->priority,
            $input->private instanceof Omitted ? $was->private : $input->private,
            $input->estimateMinutes instanceof Omitted ? $was->estimateMinutes : $input->estimateMinutes,
            $input->labelIds instanceof Omitted ? $was->labelIds : $this->strings($input->labelIds),
            $input->watcherIds instanceof Omitted ? $was->watcherIds : $this->strings($input->watcherIds),
            $input->subtasks instanceof Omitted ? $was->subtasks : $this->strings($input->subtasks),
            $input->firstOn instanceof Omitted ? $recurrence->firstIssueOn()->format('Y-m-d') : $input->firstOn,
            $input->repeat instanceof Omitted ? $recurrence->unit()->value : $input->repeat,
            $input->every instanceof Omitted ? $recurrence->every() : $input->every,
            $input->lastDayOfMonth instanceof Omitted ? $recurrence->onLastDayOfMonth() : $input->lastDayOfMonth,
            $end,
            $input->endsOn instanceof Omitted ? (RecurrenceEnd::OnDate->value === $end ? $recurrence->endOn()?->format('Y-m-d') : null) : $input->endsOn,
            $input->endsAfter instanceof Omitted ? (RecurrenceEnd::AfterCount->value === $end ? $recurrence->endAfter() : null) : $input->endsAfter,
            $input->createDaysBefore instanceof Omitted ? $was->createDaysBefore : $input->createDaysBefore,
            $input->dueDaysAfter instanceof Omitted ? $was->dueDaysAfter : $input->dueDaysAfter,
            null === $customFields ? $was->customFields : $this->encoded($customFields),
        ));
        $this->unit->run(function () use ($recurring, $template): void {
            try {
                $recurring->change($template, $this->instanceClock->today());
            } catch (\DomainException) {
                throw new WorkRefused('no_date');
            }
            $this->events->record(new RecurringTaskChanged($recurring->id(), $recurring->project()->id()));
        });
        $this->run->runOne($recurring->id());

        return $this->get($recurring->id());
    }

    /**
     * @throws WorkRefused
     */
    public function pause(string $id): RecurringTask
    {
        $recurring = $this->get($id);
        $this->refuseEnded($recurring);
        if (RecurringTaskState::Paused !== $recurring->state()) {
            $this->unit->run(function () use ($recurring): void {
                $recurring->pause($this->clock->now());
                $this->events->record(new RecurringTaskPaused($recurring->id(), $recurring->project()->id()));
            });
        }

        return $recurring;
    }

    /**
     * @return list<\DateTimeImmutable> the dates of the pause that were skipped
     *
     * @throws WorkRefused
     */
    public function resume(string $id): array
    {
        $recurring = $this->get($id);
        $this->refuseEnded($recurring);
        if (RecurringTaskState::Paused !== $recurring->state()) {
            return [];
        }
        $skipped = $this->unit->run(function () use ($recurring): array {
            $skipped = $recurring->resume($this->instanceClock->today());
            $this->events->record(new RecurringTaskResumed($recurring->id(), $recurring->project()->id()));
            // The end rule allowed no more dates after the pause, so it ended.
            if (!$recurring->nextOccurrence() instanceof \DateTimeImmutable) {
                $this->events->record(new RecurringTaskEnded($recurring->id(), $recurring->project()->id()));
            }

            return $skipped;
        });
        $this->run->runOne($id);

        return $skipped;
    }

    /**
     * @throws WorkRefused
     */
    public function end(string $id): RecurringTask
    {
        $recurring = $this->get($id);
        if (RecurringTaskState::Ended !== $recurring->state()) {
            $this->unit->run(function () use ($recurring): void {
                $recurring->end($this->clock->now());
                $this->events->record(new RecurringTaskEnded($recurring->id(), $recurring->project()->id()));
            });
        }

        return $recurring;
    }

    /**
     * @throws WorkRefused
     */
    public function skip(string $id, string $on): RecurringTask
    {
        $recurring = $this->get($id);
        $this->refuseEnded($recurring);
        $day = $this->day($on, 'on');
        $falls = RecurringSchedule::next(RecurringSchedule::open($recurring->recurrence()), 0, $day, 1)[0] ?? null;
        if ($falls?->format('Y-m-d') !== $day->format('Y-m-d')) {
            throw new WorkRefused('not_an_occurrence');
        }
        $next = $recurring->nextOccurrence();
        if (!$next instanceof \DateTimeImmutable || $day->format('Y-m-d') < $next->format('Y-m-d')) {
            throw new WorkRefused('occurrence_created');
        }
        if (\in_array($day->format('Y-m-d'), $recurring->skippedOn(), true)) {
            return $recurring;
        }
        $this->unit->run(function () use ($recurring, $day): void {
            $recurring->skip($day);
            $this->events->record(new RecurringTaskOccurrenceSkipped($recurring->id(), $recurring->project()->id(), $day->format('Y-m-d')));
            if (RecurringTaskState::Ended === $recurring->state()) {
                $this->events->record(new RecurringTaskEnded($recurring->id(), $recurring->project()->id()));
            }
        });
        // Skipping the task due now may leave the next one due already.
        $this->run->runOne($id);

        return $this->get($id);
    }

    /**
     * A recurring task the person sees: one of the organisation's people, with its project.
     *
     * @throws WorkRefused
     */
    public function get(string $id): RecurringTask
    {
        $this->work->assertManager();
        $recurring = $this->recurring->find($id);
        if (!$recurring instanceof RecurringTask || !$this->visibility->canSeeProject($recurring->project())) {
            throw new WorkRefused('unknown');
        }

        return $recurring;
    }

    /**
     * Every recurring task the person sees, the active ones first.
     *
     * @return list<RecurringTask>
     *
     * @throws WorkRefused
     */
    public function all(?RecurringTaskState $state = null): array
    {
        $this->work->assertManager();

        return array_values(array_filter(
            $this->recurring->all(),
            fn (RecurringTask $recurring): bool => (!$state instanceof RecurringTaskState || $state === $recurring->state()) && $this->visibility->canSeeProject($recurring->project()),
        ));
    }

    /**
     * The tasks it created that the person sees, the latest date first.
     *
     * @return list<Task>
     */
    public function tasks(RecurringTask $recurring, int $limit = 10): array
    {
        return array_values(array_filter($this->recurring->tasksOf($recurring, $limit), fn (Task $task): bool => !$task->isDeleted() && $this->visibility->canSeeTask($task)));
    }

    /**
     * @throws WorkRefused
     */
    private function template(Project $project, RecurringTaskFields $v): RecurringTaskTemplate
    {
        $section = null === $v->sectionId ? null : $this->work->section($v->sectionId);
        if ($section instanceof Section && $section->project()->id() !== $project->id()) {
            throw new WorkRefused('unknown', 'sectionId');
        }
        $this->work->assertTaskIn($project, $section);
        $responsible = null === $v->responsibleId ? null : $this->person($v->responsibleId, $project, $section, 'responsible');
        $watcherIds = [];
        foreach ($v->watcherIds as $watcherId) {
            $watcherIds[] = $this->person($watcherId, $project, $section, 'watcher')->id();
        }
        $labelIds = [];
        foreach ($v->labelIds as $labelId) {
            $label = $this->labels->label($labelId);
            if (!$label instanceof Label || $label->isDeleted()) {
                throw new WorkRefused('unknown', 'labelIds');
            }
            $labelIds[] = $label->id();
        }
        $title = trim($v->title);
        if ('' === $title || mb_strlen($title) > Task::MAX_TITLE_LENGTH) {
            throw new WorkRefused('name', 'title');
        }
        if (null !== $v->estimateMinutes && ($v->estimateMinutes < 1 || $v->estimateMinutes > Task::MAX_ESTIMATE_MINUTES)) {
            throw new WorkRefused('estimate');
        }
        $subtasks = array_values(array_filter(array_map(trim(...), $v->subtasks), static fn (string $title): bool => '' !== $title));
        if (\count($subtasks) > RecurringTask::MAX_SUBTASKS || [] !== array_filter($subtasks, static fn (string $title): bool => mb_strlen($title) > Task::MAX_TITLE_LENGTH)) {
            throw new WorkRefused('subtasks');
        }
        if ($v->createDaysBefore < 0 || $v->createDaysBefore > RecurringTask::MAX_CREATE_DAYS_BEFORE) {
            throw new WorkRefused('create_days_before');
        }
        if (null !== $v->dueDaysAfter && ($v->dueDaysAfter < 0 || $v->dueDaysAfter > RecurringTask::MAX_DUE_DAYS_AFTER)) {
            throw new WorkRefused('due_days_after');
        }

        return new RecurringTaskTemplate(
            $title,
            $v->description,
            $section,
            $responsible,
            Priority::tryFrom($v->priority) ?? throw new WorkRefused('unknown', 'priority'),
            $v->private,
            $v->estimateMinutes,
            $labelIds,
            $watcherIds,
            $v->customFields,
            $subtasks,
            $this->recurrence($v),
            $v->createDaysBefore,
            $v->dueDaysAfter,
        );
    }

    /**
     * @throws WorkRefused
     */
    private function recurrence(RecurringTaskFields $v): Recurrence
    {
        $unit = RecurrenceUnit::tryFrom($v->repeat) ?? throw new WorkRefused('schedule', 'repeat');
        $end = RecurrenceEnd::tryFrom($v->end) ?? throw new WorkRefused('schedule', 'end');
        $endsOn = RecurrenceEnd::OnDate === $end && null !== $v->endsOn ? $this->day($v->endsOn, 'endsOn') : null;
        try {
            return new Recurrence($this->day($v->firstOn, 'firstOn'), $unit, $v->every, $v->lastDayOfMonth, $end, $endsOn, RecurrenceEnd::AfterCount === $end ? $v->endsAfter : null);
        } catch (InvalidRecurrence) {
            throw new WorkRefused('schedule');
        }
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $values));
    }

    /**
     * An active person who may see the tasks: their id as foresi_list_project_people gives it.
     *
     * @throws WorkRefused
     */
    private function person(string $membershipId, Project $project, ?Section $section, string $reason): Membership
    {
        $membership = $this->people->membership($membershipId);
        if (!$membership instanceof Membership || !$membership->isActive() || !$this->visibility->scopeOf($membership)->allowsTaskIn($project, $section)) {
            throw new WorkRefused($reason);
        }

        return $membership;
    }

    /**
     * The custom fields as JSON keeps them, each with a value.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     *
     * @throws WorkRefused
     */
    private function encoded(array $values): array
    {
        $encoded = [];
        foreach ($values as $key => $value) {
            $definition = $this->fields->definition(TaskExtension::RECORD, $key);
            if (!$definition instanceof FieldDefinition) {
                throw new WorkRefused('custom_fields');
            }
            if (null !== $value && '' !== $value && [] !== $value) {
                $encoded[$key] = CustomFieldPayload::encoded($definition, $value);
            }
        }

        return $encoded;
    }

    /**
     * @throws WorkRefused
     */
    private function day(string $value, string $field): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->instanceClock->timezone());
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw new WorkRefused('date', $field);
        }

        return $day;
    }

    /**
     * @throws WorkRefused
     */
    private function refuseEnded(RecurringTask $recurring): void
    {
        if (RecurringTaskState::Ended === $recurring->state()) {
            throw new WorkRefused('recurring_ended');
        }
    }
}
