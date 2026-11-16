<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Api;

use App\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The feed of domain events of the organisation, read by a personal API token
 * with the read scope: the events of the last thirty days that the token's person
 * may read, in the order they happened, read on from a cursor.
 */
#[AsController]
final readonly class EventApiController
{
    public const string LIST = 'api_v1_events';

    public function __construct(private ScopeGuard $scope, private ReadableEvents $events)
    {
    }

    #[Route('/api/v1/events', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Events')]
    #[OA\Parameter(name: 'types', description: 'A comma separated list of event names or "module.*". Without it, every event the token may read.', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'invoicing.invoice.paid,invoicing.payment.recorded'))]
    #[OA\Response(response: 200, description: 'The events in ascending order of position, and the cursor to read on from.', content: new OA\JsonContent(ref: '#/components/schemas/EventPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used, such as an unknown event type.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope, or its person may not read the events of a type asked for.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] EventListQuery $query): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);

        $page = $this->events->page($query->patterns(), $query->limit, $query->cursor);

        return new JsonResponse(['data' => $page['items'], 'next_cursor' => $page['next_cursor']]);
    }
}
