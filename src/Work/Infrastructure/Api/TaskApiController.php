<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Work\Application\CommentView;
use Foresi\Work\Application\DiscussionFilter;
use Foresi\Work\Application\DiscussionItem;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The tasks the token's person sees, their comments and the labels of the
 * organisation, read with the tasks:read scope. What the person may not see,
 * a private task not shared with them included, is not found.
 */
#[AsController]
final readonly class TaskApiController
{
    public const string READ_SCOPE = 'tasks:read';
    public const string LIST = 'api_v1_tasks';
    public const string SHOW = 'api_v1_task';

    public function __construct(private ScopeGuard $scope, private WorkLists $lists, private WorkQueries $queries, private WorkJson $json)
    {
    }

    #[Route('/api/v1/tasks', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Tasks')]
    #[OA\Response(response: 200, description: 'The tasks, newest first, the cursor of the next slice and the saved view applied. Without project_id, the tasks of archived projects are left out.', content: new OA\JsonContent(ref: '#/components/schemas/TaskPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used, such as a filter with an unknown field.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the tasks:read scope, or views:read for a view.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No saved view with this id the token\'s person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] TaskListQuery $query = new TaskListQuery()): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $page = $this->lists->tasks($query, $query->view, $query->filter, Cursor::decode($query->cursor));

        return new JsonResponse(['data' => $page->items, 'next_cursor' => Cursor::next($page->nextAfter), 'applied_view' => $page->view]);
    }

    #[Route('/api/v1/tasks/{taskId}', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Tasks')]
    #[OA\Response(response: 200, description: 'The task with its description, watchers and subtasks.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskDetail')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the tasks:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No such task the token\'s person sees, or it is in the trash.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $taskId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $task = $this->queries->task($taskId) ?? throw new WorkRefused('unknown');

        return new JsonResponse(['data' => $this->json->taskDetail($task)]);
    }

    #[Route('/api/v1/tasks/{taskId}/comments', name: 'api_v1_task_comments', methods: ['GET'])]
    #[OA\Tag(name: 'Tasks')]
    #[OA\Response(response: 200, description: 'The comments of the task, oldest first, as Markdown; a deleted one is left out, and an internal one is shown to the people of the organisation only.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskComment'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the tasks:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No such task the token\'s person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function comments(string $taskId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => array_map(WorkJson::comment(...), self::commentsOf($this->queries, $taskId))]);
    }

    #[Route('/api/v1/labels', name: 'api_v1_labels', methods: ['GET'])]
    #[OA\Tag(name: 'Tasks')]
    #[OA\Response(response: 200, description: 'The labels of the organisation, by name; their ids are the label_ids of a task.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Label'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the tasks:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function labels(): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => array_map(WorkJson::label(...), $this->queries->labels())]);
    }

    /**
     * The comments of a task that stand, oldest first.
     *
     * @return list<CommentView>
     *
     * @throws WorkRefused when the task is not one the person sees
     */
    public static function commentsOf(WorkQueries $queries, string $taskId): array
    {
        $items = $queries->discussion($taskId, DiscussionFilter::Comments) ?? throw new WorkRefused('unknown');

        return array_values(array_filter(
            array_map(static fn (DiscussionItem $item): ?CommentView => $item->comment, $items),
            static fn (?CommentView $comment): bool => $comment instanceof CommentView && !$comment->deleted,
        ));
    }
}
