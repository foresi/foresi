<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Api;

use Foresi\Calendar\Application\FeedReads;
use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The person's own calendar feeds over REST, without their addresses. The
 * writes are the operations of Foresi\Calendar\Application (ADR 0032).
 */
#[AsController]
final readonly class CalendarApiController
{
    public const string READ_SCOPE = 'calendar:read';
    public const string FEEDS = 'api_v1_calendar_feeds';

    public function __construct(private ScopeGuard $scope, private FeedReads $reads, private InstanceClock $clock)
    {
    }

    #[Route('/api/v1/calendar/feeds', name: self::FEEDS, methods: ['GET'])]
    #[OA\Tag(name: 'Calendar')]
    #[OA\Response(response: 200, description: 'Your calendar feeds, oldest first, revoked ones too; never their addresses.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CalendarFeed'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the calendar:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function feeds(): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $zone = $this->clock->timezone();

        return new JsonResponse(['data' => array_map(static fn (CalendarFeed $feed): array => CalendarJson::feed($feed, $zone), $this->reads->mine())]);
    }
}
