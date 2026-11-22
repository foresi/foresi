<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Api;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The REST reads of Activities under activities:read: a record's timeline,
 * the caller's planned activities, one activity and the types. The writes are
 * operations (ADR 0032).
 */
#[AsController]
final readonly class ActivitiesApiController
{
    public const string READ_SCOPE = 'activities:read';

    public function __construct(private ScopeGuard $scope, private ActivityReads $reads, private ActivitiesJson $json)
    {
    }

    #[Route('/api/v1/activities', name: ActivitiesRoutes::ACTIVITIES, methods: ['GET'])]
    #[OA\Tag(name: 'Activities')]
    #[OA\Parameter(name: 'linked_kind', description: 'The kind of the record, such as contact.', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: 'contact'))]
    #[OA\Parameter(name: 'linked_id', description: 'The id of the record.', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'The timeline of the record: the planned activities first, the soonest on top, then the done ones, the latest first. The trash is left out.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ActivitiesActivity'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'The record is not named by a kind and an id.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the activities:read scope, or is a guest\'s.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function activities(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $activities = $this->reads->timeline($request->query->getString('linked_kind'), $request->query->getString('linked_id'));

        return new JsonResponse(['data' => array_map($this->json->activity(...), $activities)]);
    }

    #[Route('/api/v1/activities/planned', name: ActivitiesRoutes::PLANNED, methods: ['GET'], priority: 1)]
    #[OA\Tag(name: 'Activities')]
    #[OA\Parameter(name: 'from', description: 'The first day, YYYY-MM-DD; today when left out.', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'to', description: 'The day after the last, YYYY-MM-DD; 30 days after from when left out, at most 400.', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'The caller\'s own activities that are planned in the window and not done, the soonest first.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ActivitiesActivity'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'A day cannot be read, or the window is too long.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the activities:read scope, or is a guest\'s.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function planned(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $activities = $this->reads->myPlanned($this->text($request, 'from'), $this->text($request, 'to'));

        return new JsonResponse(['data' => array_map($this->json->activity(...), $activities)]);
    }

    #[Route('/api/v1/activities/{activityId}', name: ActivitiesRoutes::ACTIVITY, requirements: ['activityId' => Requirement::UUID], methods: ['GET'])]
    #[OA\Tag(name: 'Activities')]
    #[OA\Response(response: 200, description: 'The activity with its records and files, also one in the trash.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ActivitiesActivity')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the activities:read scope, or is a guest\'s.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'There is no such activity the caller may see.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function activity(string $activityId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => $this->json->activity($this->reads->activity($activityId))]);
    }

    #[Route('/api/v1/activity-types', name: ActivitiesRoutes::TYPES, methods: ['GET'])]
    #[OA\Tag(name: 'Activities')]
    #[OA\Parameter(name: 'include_archived', description: 'Also the archived types.', in: 'query', schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(response: 200, description: 'The activity types, in the order they were created.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ActivitiesType'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the activities:read scope, or is a guest\'s.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function types(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => array_map($this->json->type(...), $this->reads->types($request->query->getBoolean('include_archived')))]);
    }

    /**
     * @throws ActivitiesRefused never; the reads refuse what they cannot use
     */
    private function text(Request $request, string $name): ?string
    {
        $value = trim($request->query->getString($name));

        return '' === $value ? null : $value;
    }
}
