<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Infrastructure\Api\JsonValues;
use Foresi\Work\Application\Choice;
use Foresi\Work\Application\CommentView;
use Foresi\Work\Application\LabelBadge;
use Foresi\Work\Application\ProjectDetail;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\StatusChoice;
use Foresi\Work\Application\TaskDetail;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Contract\Field\ProjectExtension;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Infrastructure\Controller\TaskController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Projects, tasks, comments and labels as the REST API and the MCP tools
 * write them. A person is their membership id and name, as responsible_id takes
 * it. Sharing and the history stay on the screens. The url of a
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
            'overdue_tasks' => $project->overdueTasks,
            'unassigned_tasks' => $project->unassignedTasks,
            'nearest_due_on' => JsonValues::date($project->nearestDue),
            'archived' => $project->archived,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectDetail(ProjectDetail $detail): array
    {
        return $this->project($detail->row) + [
            'description' => $detail->description,
            'statuses' => array_map(static fn (StatusChoice $status, int $position): array => ['id' => $status->id, 'name' => $status->name, 'type' => $status->type->value, 'position' => $position], $detail->statuses, array_keys($detail->statuses)),
            'sections' => array_map(static fn (Choice $section, int $position): array => ['id' => $section->id, 'name' => $section->name, 'position' => $position], $detail->sections, array_keys($detail->sections)),
        ];
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
            'starred' => $task->starred,
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
            'recurring_task' => $detail->recurringTask instanceof Choice ? ['id' => $detail->recurringTask->id, 'name' => $detail->recurringTask->name] : null,
            'occurrence_on' => $detail->occurrenceOn?->format('Y-m-d'),
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
}
