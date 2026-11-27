<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Work\Application\Comments;
use Foresi\Work\Application\ManageComments;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Application\Projects;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Application\WorkUnit;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Contract\ProjectClients;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\WorkflowTemplate;

/**
 * The writes of projects, tasks and comments over the API and MCP (ADR
 * 0032). Each runs the use cases the screens run, so WorkVisibility decides
 * who may, and in one unit of work: a call that changes several fields of a
 * task is saved whole or not at all, and is one step of its history. Every
 * method answers the id of what to read back. Without the address book
 * (ProjectClients) a project has no client: a write that names one is refused,
 * while the reads still return the client a project keeps.
 */
final readonly class WorkWrites
{
    public function __construct(
        private ManageTasks $tasks,
        private ManageProjects $projects,
        private ManageComments $comments,
        private WorkQueries $queries,
        private Comments $commentStore,
        private WorkUnit $unit,
        private Projects $store,
        private WorkVisibility $visibility,
        private ?ProjectClients $clients = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $customFields by key, as CustomFieldPayload::read() gives them; null when the caller offers none
     *
     * @throws WorkRefused
     * @throws FieldRefused nothing is stored
     */
    public function createTask(CreateTaskInput $input, ?array $customFields = null): string
    {
        $dueOn = null === $input->dueOn ? null : $this->day($input->dueOn, 'dueOn');

        return $this->unit->run(function () use ($input, $dueOn, $customFields): string {
            $task = $this->titled(fn (): \Foresi\Work\Domain\Task => $this->tasks->create(
                $input->projectId,
                $input->title,
                $input->sectionId,
                $input->parentId,
                $input->statusId,
                $input->responsibleId,
                $dueOn,
                Priority::from($input->priority),
                customFields: $customFields,
            ));
            // Saved, so the changes below find it as the task page does.
            $this->store->flush();
            if ('' !== $input->description) {
                $this->tasks->describe($task->id(), $input->description);
            }
            if ([] !== $input->labelIds) {
                $this->tasks->setLabels($task->id(), $input->labelIds);
            }

            return $task->id();
        });
    }

    /**
     * @param array<string, mixed>|null $customFields the custom fields to change by key; null to keep them
     *
     * @throws WorkRefused
     * @throws FieldRefused nothing is stored
     */
    public function updateTask(UpdateTaskInput $input, ?array $customFields = null): string
    {
        $id = $input->taskId;
        $dueOn = \is_string($input->dueOn) ? $this->day($input->dueOn, 'dueOn') : null;
        $this->unit->run(function () use ($input, $id, $dueOn, $customFields): void {
            if (!$input->title instanceof Omitted) {
                $title = $input->title;
                $this->titled(fn () => $this->tasks->rename($id, $title));
            }
            if (!$input->description instanceof Omitted) {
                $this->tasks->describe($id, $input->description);
            }
            if (!$input->statusId instanceof Omitted) {
                $this->tasks->changeStatus($id, $input->statusId);
            }
            if (!$input->responsibleId instanceof Omitted) {
                $this->tasks->assign($id, $input->responsibleId);
            }
            if (!$input->dueOn instanceof Omitted) {
                $this->tasks->setDue($id, $dueOn);
            }
            if (!$input->priority instanceof Omitted) {
                $this->tasks->prioritise($id, Priority::from($input->priority));
            }
            if (!$input->sectionId instanceof Omitted) {
                $this->tasks->moveToSection($id, $input->sectionId);
            }
            if (!$input->labelIds instanceof Omitted) {
                $this->tasks->setLabels($id, $input->labelIds);
            }
            if (null !== $customFields) {
                $this->tasks->setCustomFields($id, $customFields);
            }
        });

        return $id;
    }

    /**
     * @throws WorkRefused
     */
    public function trashTask(TrashTaskInput $input): string
    {
        return $this->tasks->delete($input->taskId)->id();
    }

    /**
     * @throws WorkRefused
     */
    public function restoreTask(RestoreTaskInput $input): string
    {
        return $this->tasks->restore($input->taskId)->id();
    }

    /**
     * @return array{string, string} the ids of the comment and its task
     *
     * @throws WorkRefused
     */
    public function comment(AddTaskCommentInput $input): array
    {
        // The screen never offers a guest an internal comment; the API says no rather than posting it for everyone.
        if ($input->internal && !$this->visibility->canSeeInternal()) {
            throw new WorkRefused('not_allowed');
        }
        $comment = $this->comments->add($input->taskId, $input->body, $input->internal);

        return [$comment->id(), $comment->task()->id()];
    }

    /**
     * @return array{string, string} the ids of the comment and its task
     *
     * @throws WorkRefused
     */
    public function editComment(UpdateTaskCommentInput $input): array
    {
        // Its task, read first; the use case refuses what the person may not see.
        $task = $this->commentStore->comment($input->commentId)?->task()->id() ?? throw new WorkRefused('unknown');
        $this->comments->edit($input->commentId, $input->body);

        return [$input->commentId, $task];
    }

    /**
     * @return array{string, string} the ids of the comment and its task
     *
     * @throws WorkRefused
     */
    public function trashComment(TrashTaskCommentInput $input): array
    {
        $comment = $this->comments->delete($input->commentId);

        return [$comment->id(), $comment->task()->id()];
    }

    /**
     * @param array<string, mixed>|null $customFields by key, as CustomFieldPayload::read() gives them; null when the caller offers none
     *
     * @throws WorkRefused
     * @throws FieldRefused nothing is stored
     */
    public function createProject(CreateProjectInput $input, ?array $customFields = null): string
    {
        $this->unlessClientUnavailable($input->clientId);

        return $this->named(fn (): \Foresi\Work\Domain\Project => $this->projects->create($input->name, $input->clientId, WorkflowTemplate::from($input->workflow), $input->description, $customFields))->id();
    }

    /**
     * @param array<string, mixed>|null $customFields the custom fields to change by key; null to keep them
     *
     * @throws WorkRefused
     * @throws FieldRefused nothing is stored
     */
    public function updateProject(UpdateProjectInput $input, ?array $customFields = null): string
    {
        if (!$input->clientId instanceof Omitted) {
            $this->unlessClientUnavailable($input->clientId);
        }
        $project = $this->queries->project($input->projectId) ?? throw new WorkRefused('unknown');
        $this->named(fn () => $this->projects->edit(
            $input->projectId,
            $input->name instanceof Omitted ? $project->row->name : $input->name,
            $input->clientId instanceof Omitted ? $project->row->clientId : $input->clientId,
            $input->description instanceof Omitted ? $project->description : $input->description,
            $customFields,
        ));

        return $input->projectId;
    }

    /**
     * @throws WorkRefused
     */
    public function archiveProject(ArchiveProjectInput $input): string
    {
        $this->projects->archive($input->projectId);

        return $input->projectId;
    }

    /**
     * @throws WorkRefused
     */
    public function restoreProject(RestoreProjectInput $input): string
    {
        $this->projects->restore($input->projectId);

        return $input->projectId;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $change
     *
     * @return T
     */
    private function titled(\Closure $change): mixed
    {
        try {
            return $change();
        } catch (WorkRefused $refused) {
            throw 'name' === $refused->reason ? $refused->about('title') : $refused;
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $change
     *
     * @return T
     */
    private function named(\Closure $change): mixed
    {
        try {
            return $change();
        } catch (WorkRefused $refused) {
            throw 'name' === $refused->reason ? $refused->about('name') : $refused;
        }
    }

    private function day(string $value, string $field): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$day instanceof \DateTimeImmutable || $day->format('Y-m-d') !== $value) {
            throw new WorkRefused('date', $field);
        }

        return $day;
    }

    /**
     * Refuses a client where the address book is not installed, so a project has none.
     *
     * @throws WorkRefused
     */
    private function unlessClientUnavailable(?string $clientId): void
    {
        if (null !== $clientId && !$this->clients instanceof ProjectClients) {
            throw new WorkRefused('client_unavailable');
        }
    }
}
