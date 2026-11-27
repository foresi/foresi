<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Operation\InvalidInput;
use Foresi\Work\Application\ManageRecurringTasks;
use Foresi\Work\Domain\RecurringTaskState;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Recurring tasks read over REST with the tasks:read scope, by the people of the
 * organisation, each with its project. The writes are operations
 * (CreateRecurringTaskInput and the rest); what is refused is answered by
 * OperationErrors.
 */
#[AsController]
final readonly class RecurringTaskApiController
{
    public const string LIST = 'api_v1_recurring_tasks';
    public const string SHOW = 'api_v1_recurring_task';
    public const string PREVIEW = 'api_v1_recurring_task_preview';

    public function __construct(private ScopeGuard $scope, private ManageRecurringTasks $recurring, private RecurringTaskJson $json)
    {
    }

    #[Route('/api/v1/recurring-tasks', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Recurring tasks')]
    #[OA\Parameter(name: 'state', description: 'Only the recurring tasks in this state.', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'paused', 'ended']))]
    #[OA\Parameter(name: 'project_id', description: 'Only those of this project.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'The recurring tasks of the projects the person sees: the active ones first, the soonest next date first.', content: new OA\JsonContent(ref: '#/components/schemas/RecurringTaskPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the tasks:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(Request $request): JsonResponse
    {
        $this->scope->require(TaskApiController::READ_SCOPE);
        $state = $request->query->getString('state');
        $known = RecurringTaskState::tryFrom($state);
        if ('' !== $state && null === $known) {
            throw new InvalidInput('state', 'must be active, paused or ended.');
        }
        $projectId = $request->query->getString('project_id');
        $all = $this->recurring->all($known);
        if ('' !== $projectId) {
            $all = array_values(array_filter($all, static fn (\Foresi\Work\Domain\RecurringTask $recurring): bool => $recurring->project()->id() === $projectId));
        }

        return new JsonResponse(['data' => array_map(RecurringTaskJson::item(...), $all)]);
    }

    #[Route('/api/v1/recurring-tasks/{recurringTaskId}', name: self::SHOW, requirements: ['recurringTaskId' => Requirement::UUID], methods: ['GET'])]
    #[OA\Tag(name: 'Recurring tasks')]
    #[OA\Response(response: 200, description: 'The recurring task with what each task gets, its schedule, its next five tasks and the last tasks it created.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RecurringTaskDetail')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the tasks:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No such recurring task in a project the person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $recurringTaskId): JsonResponse
    {
        $this->scope->require(TaskApiController::READ_SCOPE);

        return new JsonResponse(['data' => $this->json->detail($this->recurring->get($recurringTaskId))]);
    }

    #[Route('/api/v1/recurring-tasks/{recurringTaskId}/preview', name: self::PREVIEW, requirements: ['recurringTaskId' => Requirement::UUID], methods: ['GET'])]
    #[OA\Tag(name: 'Recurring tasks')]
    #[OA\Parameter(name: 'count', description: 'How many next tasks, 1 to 24; 5 by default.', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 24, minimum: 1))]
    #[OA\Response(response: 200, description: 'The next tasks it creates, with the day each is created, its due date and title; none while paused or ended.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RecurringTaskOccurrence'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the tasks:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No such recurring task in a project the person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function preview(string $recurringTaskId, Request $request): JsonResponse
    {
        $this->scope->require(TaskApiController::READ_SCOPE);
        $count = $request->query->getInt('count', ManageRecurringTasks::PREVIEW);
        if ($count < 1 || $count > ManageRecurringTasks::MAX_PREVIEW) {
            throw new InvalidInput('count', 'must be 1 to 24.');
        }

        return new JsonResponse(['data' => RecurringTaskJson::occurrences($this->recurring->get($recurringTaskId), $count)]);
    }
}
