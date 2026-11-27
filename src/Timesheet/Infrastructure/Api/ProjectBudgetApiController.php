<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Timesheet\Application\ProjectBudgetReads;
use Foresi\Work\Infrastructure\Api\ProjectApiController;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The time budget of a project the token's person sees, read with the
 * projects:read scope as the project itself.
 */
#[AsController]
final readonly class ProjectBudgetApiController
{
    public const string BUDGET = 'api_v1_project_budget';

    public function __construct(private ScopeGuard $scope, private ProjectBudgetReads $budgets)
    {
    }

    #[Route('/api/v1/projects/{projectId}/budget', name: self::BUDGET, methods: ['GET'])]
    #[OA\Tag(name: 'Projects')]
    #[OA\Response(response: 200, description: 'The time budget of the project: budget, used and left in hours and minutes, which time counts, the sum of the task estimates and the dated changes; changes is null for a guest.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectBudget')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the projects:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No such project the token\'s person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function budget(string $projectId): JsonResponse
    {
        $this->scope->require(ProjectApiController::READ_SCOPE);

        return new JsonResponse(['data' => TimesheetJson::budget($this->budgets->budget($projectId))]);
    }
}
