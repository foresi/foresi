<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Application\Api\Omitted;
use Foresi\Work\Application\Comments;
use Foresi\Work\Application\ManageComments;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Application\ManageTime;
use Foresi\Work\Application\Projects;
use Foresi\Work\Application\TimeEntryRow;
use Foresi\Work\Application\TimeQueries;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Application\WorkUnit;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\WorkflowTemplate;

/**
 * The writes of projects, tasks, comments and time over the API and MCP (ADR
 * 0032). Each runs the use cases the screens run, so WorkVisibility decides
 * who may, and in one unit of work: a call that changes several fields of a
 * task is saved whole or not at all, and is one step of its history. Every
 * method answers the id of what to read back.
 */
final readonly class WorkWrites
{
    public function __construct(
        private ManageTasks $tasks,
        private ManageProjects $projects,
        private ManageComments $comments,
        private ManageTime $time,
        private WorkQueries $queries,
        private TimeQueries $timeQueries,
        private Comments $commentStore,
        private WorkUnit $unit,
        private Projects $store,
    ) {
    }

    /**
     * @throws WorkRefused
     */
    public function createTask(CreateTaskInput $input): string
    {
        $dueOn = null === $input->dueOn ? null : $this->day($input->dueOn, 'dueOn');

        return $this->unit->run(function () use ($input, $dueOn): string {
            $task = $this->titled(fn (): \Foresi\Work\Domain\Task => $this->tasks->create(
                $input->projectId,
                $input->title,
                $input->sectionId,
                $input->parentId,
                $input->statusId,
                $input->responsibleId,
                $dueOn,
                Priority::from($input->priority),
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
     * @throws WorkRefused
     */
    public function updateTask(UpdateTaskInput $input): string
    {
        $id = $input->taskId;
        $dueOn = \is_string($input->dueOn) ? $this->day($input->dueOn, 'dueOn') : null;
        $this->unit->run(function () use ($input, $id, $dueOn): void {
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
     * @throws WorkRefused
     */
    public function createProject(CreateProjectInput $input): string
    {
        return $this->named(fn (): \Foresi\Work\Domain\Project => $this->projects->create($input->name, $input->clientId, WorkflowTemplate::from($input->workflow), $input->description))->id();
    }

    /**
     * @throws WorkRefused
     */
    public function updateProject(UpdateProjectInput $input): string
    {
        $project = $this->queries->project($input->projectId) ?? throw new WorkRefused('unknown');
        $this->named(fn () => $this->projects->edit(
            $input->projectId,
            $input->name instanceof Omitted ? $project->row->name : $input->name,
            $input->clientId instanceof Omitted ? $project->row->clientId : $input->clientId,
            $input->description instanceof Omitted ? $project->description : $input->description,
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
     * @throws WorkRefused
     */
    public function logTime(LogTimeInput $input): string
    {
        return $this->time->log(
            $input->projectId,
            $input->taskId,
            $this->day($input->workedOn, 'workedOn'),
            $input->minutes,
            $this->moment($input->startedAt, 'startedAt'),
            $this->moment($input->endedAt, 'endedAt'),
            $input->note,
            $input->billable,
        )->id();
    }

    /**
     * @throws WorkRefused
     */
    public function startTimer(StartTimerInput $input): string
    {
        return $this->time->startTimer($input->projectId, $input->taskId, $input->note, $input->billable)->id();
    }

    /**
     * @throws WorkRefused when the entry is not the running timer of the person
     */
    public function stopTimer(StopTimerInput $input): string
    {
        $running = $this->timeQueries->running();
        if (!$running instanceof TimeEntryRow || $running->id !== $input->entryId) {
            throw new WorkRefused('timer_not_running', 'entryId');
        }

        $this->time->stopTimer();

        return $input->entryId;
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

    private function moment(?string $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $moment = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);
        if (!$moment instanceof \DateTimeImmutable) {
            throw new WorkRefused('date', $field);
        }

        return $moment;
    }
}
