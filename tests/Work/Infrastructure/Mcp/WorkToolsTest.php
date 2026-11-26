<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Mcp;

use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;
use Foresi\Work\Infrastructure\Mcp\GetProjectTool;
use Foresi\Work\Infrastructure\Mcp\GetTaskTool;
use Foresi\Work\Infrastructure\Mcp\ListLabelsTool;
use Foresi\Work\Infrastructure\Mcp\ListProjectPeopleTool;
use Foresi\Work\Infrastructure\Mcp\ListTaskCommentsTool;
use Foresi\Work\Infrastructure\Mcp\SearchProjectsTool;
use Foresi\Work\Infrastructure\Mcp\SearchTasksTool;
use Foresi\Work\Infrastructure\Mcp\SearchTimeEntriesTool;

/**
 * The read tools of Work answer what the REST endpoints answer, with the same
 * arguments and paging, and each needs the scope of its resource.
 */
final class WorkToolsTest extends WorkApiTestCase
{
    public function testTheToolsDescribeTheirArgumentsAndScopes(): void
    {
        $tools = [
            SearchProjectsTool::class => ['foresi_search_projects', 'projects:read', ['query', 'archived', 'client_id', 'view_id', 'filter', 'limit', 'cursor']],
            GetProjectTool::class => ['foresi_get_project', 'projects:read', ['project_id']],
            ListProjectPeopleTool::class => ['foresi_list_project_people', 'tasks:read', ['project_id', 'task_id']],
            SearchTasksTool::class => ['foresi_search_tasks', 'tasks:read', ['query', 'project_id', 'assignee', 'priority', 'label_id', 'client_id', 'created_from', 'created_to', 'updated_from', 'updated_to', 'sort', 'status_type', 'finished', 'due', 'view_id', 'starred', 'filter', 'limit', 'cursor']],
            GetTaskTool::class => ['foresi_get_task', 'tasks:read', ['task_id']],
            ListTaskCommentsTool::class => ['foresi_list_task_comments', 'tasks:read', ['task_id']],
            ListLabelsTool::class => ['foresi_list_labels', 'tasks:read', []],
            SearchTimeEntriesTool::class => ['foresi_search_time_entries', 'time_entries:read', ['project_id', 'task_id', 'person', 'from', 'until', 'limit', 'cursor']],
        ];
        foreach ($tools as $class => [$name, $scope, $arguments]) {
            $tool = $this->tool($class);
            self::assertSame($name, $tool->name());
            self::assertSame($scope, $tool->scope(), $name);
            if ([] === $arguments) {
                self::assertEquals(new \stdClass(), $tool->inputSchema()['properties'], $name);
            } else {
                self::assertSame($arguments, self::argumentNames($tool), $name);
            }
            self::assertFalse($tool->inputSchema()['additionalProperties'], $name);
            self::assertNotSame('', $tool->description());
        }
        self::assertSame(['open', 'active', 'done', 'closed'], self::argument($this->tool(SearchTasksTool::class), 'status_type')['enum']);
    }

    public function testTheToolsAnswerWhatRestAnswers(): void
    {
        $project = $this->project();
        $me = $this->membershipOf($this->user)->id();
        $task = $this->task($project, 'Mine', $me);
        $this->task($project, 'Theirs');
        $this->comment($task, 'A note');
        $this->label('Urgent');
        $this->logged($project, $task, 30, $this->user);

        self::assertSame($this->rest('/api/v1/tasks', ['assignee' => 'me']), self::wire($this->call(SearchTasksTool::class, ['assignee' => 'me'])->data)['items']);
        self::assertSame($this->rest('/api/v1/tasks', ['assignee' => 'none', 'priority' => 'none,low', 'sort' => '-title']), self::wire($this->call(SearchTasksTool::class, ['assignee' => 'none', 'priority' => ['none', 'low'], 'sort' => '-title'])->data)['items']);
        self::assertSame($this->rest('/api/v1/tasks/'.$task), self::wire($this->call(GetTaskTool::class, ['task_id' => $task])->data));
        self::assertSame($this->rest('/api/v1/tasks/'.$task.'/comments'), $this->call(ListTaskCommentsTool::class, ['task_id' => $task])->data['items']);
        self::assertSame($this->rest('/api/v1/projects'), self::wire($this->call(SearchProjectsTool::class)->data)['items']);
        self::assertSame($this->rest('/api/v1/projects/'.$project->id()), self::wire($this->call(GetProjectTool::class, ['project_id' => $project->id()])->data));
        self::assertSame($this->rest('/api/v1/projects/'.$project->id().'/people', ['task_id' => $task]), $this->call(ListProjectPeopleTool::class, ['project_id' => $project->id(), 'task_id' => $task])->data['items']);
        self::assertSame($this->rest('/api/v1/labels'), $this->call(ListLabelsTool::class)->data['items']);
        self::assertSame($this->rest('/api/v1/time-entries'), $this->call(SearchTimeEntriesTool::class)->data['items']);

        // A saved view needs the views:read scope, which only a token carries.
        $this->assertRefused('insufficient_scope', 'The token does not have the "views:read" scope a saved view needs.', SearchTasksTool::class, ['view_id' => 'work.my_tasks.assigned']);
    }

    public function testTheSearchPagesAsRestDoes(): void
    {
        $project = $this->project();
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->task($project, $title);
        }

        self::assertSame($this->pages('/api/v1/tasks', 2), $this->toolPages(SearchTasksTool::class, 2));
        $this->assertRefused('invalid_argument', 'The argument "cursor" is not one a search gave. Pass next_cursor of the previous answer unchanged, or leave cursor out for the first page.', SearchTasksTool::class, ['cursor' => 'nonsense']);
        self::assertSame('invalid_argument', $this->refusal(SearchTasksTool::class, ['due' => 'someday'])->errorCode);
    }

    public function testAnUnknownRecordIsNotFound(): void
    {
        self::assertSame('not_found', $this->refusal(GetProjectTool::class, ['project_id' => 'nope'])->errorCode);
        self::assertSame('not_found', $this->refusal(ListTaskCommentsTool::class, ['task_id' => 'nope'])->errorCode);
        self::assertSame('not_found', $this->refusal(ListProjectPeopleTool::class, ['project_id' => 'nope'])->errorCode);
    }
}
