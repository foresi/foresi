<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The projects the token's person sees, read with the projects:read scope, and
 * who may be responsible for their tasks, with tasks:read. What the person may
 * not see is not found (WorkVisibility).
 */
#[AsController]
final readonly class ProjectApiController
{
    public const string READ_SCOPE = 'projects:read';
    public const string LIST = 'api_v1_projects';
    public const string SHOW = 'api_v1_project';

    public function __construct(private ScopeGuard $scope, private WorkLists $lists, private WorkQueries $queries, private WorkJson $json)
    {
    }

    #[Route('/api/v1/projects', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Projects')]
    #[OA\Response(response: 200, description: 'The projects, newest first, the cursor of the next slice and the saved view applied.', content: new OA\JsonContent(ref: '#/components/schemas/ProjectPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used, such as a filter with an unknown field.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the projects:read scope, or views:read for a view.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No saved view with this id the token\'s person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] ProjectListQuery $query = new ProjectListQuery()): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $page = $this->lists->projects($query, $query->view, $query->filter, Cursor::decode($query->cursor));

        return new JsonResponse(['data' => $page->items, 'next_cursor' => Cursor::next($page->nextAfter), 'applied_view' => $page->view]);
    }

    #[Route('/api/v1/projects/{projectId}', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Projects')]
    #[OA\Response(response: 200, description: 'The project with its statuses and the sections the person sees.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectDetail')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the projects:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No such project the token\'s person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $projectId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $project = $this->queries->project($projectId) ?? throw new WorkRefused('unknown');

        return new JsonResponse(['data' => $this->json->projectDetail($project)]);
    }

    #[Route('/api/v1/projects/{projectId}/people', name: 'api_v1_project_people', methods: ['GET'])]
    #[OA\Tag(name: 'Projects')]
    #[OA\Parameter(name: 'task_id', description: 'Only who may be responsible for this task of the project: who sees it.', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'The active people who may be responsible for a task of the project, by name; their id is the responsible_id of a task.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Reference'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the tasks:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No such project, or task of it, the token\'s person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function people(string $projectId, #[MapQueryString(validationFailedStatusCode: 400)] ProjectPeopleQuery $query = new ProjectPeopleQuery()): JsonResponse
    {
        $this->scope->require(TaskApiController::READ_SCOPE);
        $people = $this->queries->projectPeople($projectId, $query->taskId) ?? throw new WorkRefused('unknown');

        return new JsonResponse(['data' => array_map(WorkJson::person(...), $people)]);
    }
}
