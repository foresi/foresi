<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Infrastructure\Api\JsonValues;
use Foresi\Work\Application\BudgetChangeRow;
use Foresi\Work\Application\Choice;
use Foresi\Work\Application\CommentView;
use Foresi\Work\Application\LabelBadge;
use Foresi\Work\Application\ProjectBudget;
use Foresi\Work\Application\ProjectDetail;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\StatusChoice;
use Foresi\Work\Application\TaskDetail;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\TimeEntryRow;
use Foresi\Work\Contract\Field\ProjectExtension;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Infrastructure\Controller\TaskController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Projects, tasks, comments, labels and time as the REST API and the MCP tools
 * write them. A person is their membership id and name, as responsible_id takes
 * it. Hourly rates, sharing and the history stay on the screens. The url of a
 * task is its page, for a person to open. A project and a task carry their
 * custom fields, a list read in one go.
 */
final readonly class WorkJson
{
    public function __construct(private UrlGeneratorInterface $urls, private CustomFieldPayload $customFields)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function project(ProjectRow $project): array
    {
        return $this->customFields->attachOne(ProjectExtension::RECORD, $this->projectRow($project));
    }

    /**
     * @param list<ProjectRow> $projects
     *
     * @return list<array<string, mixed>>
     */
    public function projects(array $projects): array
    {
        return $this->customFields->attach(ProjectExtension::RECORD, array_map($this->projectRow(...), $projects));
    }

    /**
     * @return array<string, mixed>
     */
    private function projectRow(ProjectRow $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'client' => null === $project->clientId ? null : ['id' => $project->clientId, 'name' => $project->clientName],
            'visibility' => $project->visibility->value,
            'workflow' => $project->template->value,
            'open_tasks' => $project->openTasks,
            'nearest_due_on' => JsonValues::date($project->nearestDue),
            'archived' => $project->archived,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectDetail(ProjectDetail $detail, ?ProjectBudget $budget = null): array
    {
        return $this->project($detail->row) + [
            'description' => $detail->description,
            'billable_by_default' => $detail->billableByDefault,
            'statuses' => array_map(static fn (StatusChoice $status, int $position): array => ['id' => $status->id, 'name' => $status->name, 'type' => $status->type->value, 'position' => $position], $detail->statuses, array_keys($detail->statuses)),
            'sections' => array_map(static fn (Choice $section, int $position): array => ['id' => $section->id, 'name' => $section->name, 'position' => $position], $detail->sections, array_keys($detail->sections)),
            'budget' => $budget instanceof ProjectBudget && $budget->hasBudget() ? self::budgetSummary($budget) : null,
        ];
    }

    /**
     * The time budget of a project in hours, as foresi_get_project shows it.
     *
     * @return array{hours: float|null, used_hours: float, left_hours: float|null, counting: string, estimate_hours: float}
     */
    public static function budgetSummary(ProjectBudget $budget): array
    {
        return [
            'hours' => self::hours($budget->budgetMinutes),
            'used_hours' => self::hours($budget->usedMinutes),
            'left_hours' => self::hours($budget->leftMinutes()),
            'counting' => $budget->counting->value,
            'estimate_hours' => self::hours($budget->estimateMinutes),
        ];
    }

    /**
     * The time budget of a project with its change log; the changes are null for a guest.
     *
     * @return array<string, mixed>
     */
    public static function budget(ProjectBudget $budget): array
    {
        return ['project_id' => $budget->projectId] + self::budgetSummary($budget) + [
            'minutes' => $budget->budgetMinutes,
            'used_minutes' => $budget->usedMinutes,
            'left_minutes' => $budget->leftMinutes(),
            'over_budget' => $budget->isOver(),
            'estimate_minutes' => $budget->estimateMinutes,
            'changes' => null === $budget->changes ? null : array_map(static fn (BudgetChangeRow $change): array => [
                'id' => $change->id,
                'effective_on' => JsonValues::date($change->effectiveOn),
                'hours' => self::hours($change->minutes),
                'minutes' => $change->minutes,
                'note' => $change->note,
                'source_activity_id' => $change->sourceActivityId,
                'created_by' => ['id' => $change->createdBy, 'name' => $change->authorName],
                'created_at' => JsonValues::timestamp($change->createdAt),
            ], $budget->changes),
        ];
    }

    /**
     * @return ($minutes is int ? float : null)
     */
    private static function hours(?int $minutes): ?float
    {
        return null === $minutes ? null : round($minutes / 60, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function task(TaskRow $task): array
    {
        return $this->customFields->attachOne(TaskExtension::RECORD, $this->taskRow($task));
    }

    /**
     * @param list<TaskRow> $tasks
     *
     * @return list<array<string, mixed>>
     */
    public function tasks(array $tasks): array
    {
        return $this->customFields->attach(TaskExtension::RECORD, array_map($this->taskRow(...), $tasks));
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(TaskRow $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'project' => ['id' => $task->projectId, 'name' => $task->projectName],
            'section_id' => $task->sectionId,
            'parent_id' => $task->parentId,
            'status' => ['id' => $task->statusId, 'name' => $task->statusName, 'type' => $task->statusType->value],
            'responsible' => null === $task->responsibleId ? null : ['id' => $task->responsibleId, 'name' => $task->responsibleName],
            'due_on' => JsonValues::date($task->dueOn),
            'priority' => $task->priority->value,
            'labels' => array_map(self::label(...), $task->labels),
            'subtask_count' => $task->subtaskCount,
            'done' => $task->done,
            'private' => $task->private,
            'url' => $this->urls->generate(TaskController::ROUTE, ['id' => $task->id], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function taskDetail(TaskDetail $detail): array
    {
        return $this->task($detail->task) + [
            'description' => $detail->description,
            'section' => null === $detail->task->sectionId ? null : ['id' => $detail->task->sectionId, 'name' => $detail->sectionName],
            'parent' => $detail->parent instanceof Choice ? ['id' => $detail->parent->id, 'title' => $detail->parent->name] : null,
            'watchers' => array_map(self::person(...), $detail->watchers),
            'subtasks' => $this->tasks($detail->task->subtasks),
            'estimate_minutes' => $detail->estimateMinutes,
            'project_archived' => $detail->projectArchived,
            'created_at' => JsonValues::timestamp($detail->createdAt),
            'completed_at' => JsonValues::timestamp($detail->completedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function comment(CommentView $comment): array
    {
        return [
            'id' => $comment->id,
            'author' => ['id' => $comment->authorId, 'name' => $comment->authorName],
            'text' => $comment->text,
            'internal' => $comment->internal,
            'created_at' => JsonValues::timestamp($comment->createdAt),
            'edited_at' => JsonValues::timestamp($comment->editedAt),
        ];
    }

    /**
     * @return array{id: string, name: string, colour: string}
     */
    public static function label(LabelBadge $label): array
    {
        return ['id' => $label->id, 'name' => $label->name, 'colour' => $label->colour->value];
    }

    /**
     * @return array{id: string, name: string}
     */
    public static function person(Choice $person): array
    {
        return ['id' => $person->id, 'name' => $person->name];
    }

    /**
     * @return array<string, mixed>
     */
    public static function timeEntry(TimeEntryRow $entry): array
    {
        return [
            'id' => $entry->id,
            'project' => ['id' => $entry->projectId, 'name' => $entry->projectName],
            'task' => null === $entry->taskId ? null : ['id' => $entry->taskId, 'title' => $entry->taskTitle],
            'person' => ['id' => $entry->membershipId, 'name' => $entry->personName],
            'worked_on' => JsonValues::date($entry->workedOn),
            'minutes' => $entry->minutes,
            'started_at' => JsonValues::timestamp($entry->startedAt),
            'ended_at' => JsonValues::timestamp($entry->endedAt),
            'note' => $entry->note,
            'billable' => $entry->billable,
            'running' => $entry->running,
            'invoiced' => $entry->invoiced,
        ];
    }
}
