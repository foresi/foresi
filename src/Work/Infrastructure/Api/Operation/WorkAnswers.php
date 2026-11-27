<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Infrastructure\Api\ProjectApiController;
use Foresi\Work\Infrastructure\Api\TaskApiController;
use Foresi\Work\Infrastructure\Api\WorkJson;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the writes of Work answer: what the read endpoints and tools answer for
 * the task, project or comment as it is now.
 */
final readonly class WorkAnswers
{
    public function __construct(private WorkQueries $queries, private WorkJson $json, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param string $summary with %s for the title of the task
     */
    public function task(string $id, string $summary, bool $created = false): OperationOutput
    {
        $task = $this->queries->task($id) ?? throw new WorkRefused('unknown');

        return new OperationOutput($this->json->taskDetail($task), \sprintf($summary, $task->task->title), $created ? $this->urls->generate(TaskApiController::SHOW, ['taskId' => $id]) : null);
    }

    /**
     * @param string $summary with %s for the name of the project
     */
    public function project(string $id, string $summary, bool $created = false): OperationOutput
    {
        $project = $this->queries->project($id) ?? throw new WorkRefused('unknown');

        return new OperationOutput($this->json->projectDetail($project), \sprintf($summary, $project->row->name), $created ? $this->urls->generate(ProjectApiController::SHOW, ['projectId' => $id]) : null);
    }

    public function comment(string $commentId, string $taskId, string $summary, bool $created = false): OperationOutput
    {
        foreach (TaskApiController::commentsOf($this->queries, $taskId) as $comment) {
            if ($comment->id === $commentId) {
                return new OperationOutput(WorkJson::comment($comment), $summary, $created ? $this->urls->generate('api_v1_task_comments', ['taskId' => $taskId]) : null);
            }
        }

        throw new WorkRefused('unknown');
    }
}
