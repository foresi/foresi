<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Api\Cursor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The time the token's person sees, read with the time_entries:read scope:
 * on the tasks they see, and without a task where the whole project is open
 * to them. Hourly rates stay on the screens.
 */
#[AsController]
final readonly class TimeEntryApiController
{
    public const string READ_SCOPE = 'time_entries:read';
    public const string LIST = 'api_v1_time_entries';

    public function __construct(private ScopeGuard $scope, private WorkLists $lists)
    {
    }

    #[Route('/api/v1/time-entries', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Time')]
    #[OA\Response(response: 200, description: 'The time entries, newest first, a running timer included without minutes, and the cursor of the next slice.', content: new OA\JsonContent(ref: '#/components/schemas/TimeEntryPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used, such as a date that is not YYYY-MM-DD.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the time_entries:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] TimeEntryListQuery $query = new TimeEntryListQuery()): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $page = $this->lists->timeEntries($query, Cursor::decode($query->cursor));

        return new JsonResponse(['data' => $page->items, 'next_cursor' => Cursor::next($page->nextAfter)]);
    }
}
