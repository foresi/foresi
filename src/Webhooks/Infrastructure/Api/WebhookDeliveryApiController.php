<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Api;

use App\Shared\Application\Api\ScopeGuard;
use App\Shared\Infrastructure\Api\Cursor;
use App\Webhooks\Application\ManageWebhookEndpoints;
use App\Webhooks\Application\Query\WebhookDeliveryLog;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What was sent to an endpoint: its deliveries of the last thirty days, each
 * with its attempts, read with the read scope, and a finished one redelivered
 * by hand with the write scope.
 */
#[AsController]
final readonly class WebhookDeliveryApiController
{
    public const string LIST = 'api_v1_webhook_deliveries';
    public const string SHOW = 'api_v1_webhook_delivery';
    public const string REDELIVER = 'api_v1_webhook_delivery_redeliver';

    public function __construct(private ScopeGuard $scope, private WebhookDeliveryLog $log, private ManageWebhookEndpoints $manage)
    {
    }

    #[Route('/api/v1/webhook-endpoints/{id}/deliveries', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\Response(response: 200, description: 'The deliveries of the endpoint, newest first, and the cursor of the next slice.', content: new OA\JsonContent(ref: '#/components/schemas/WebhookDeliveryPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope, or the endpoint subscribes to events its person may not read.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such endpoint.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(string $id, #[MapQueryString(validationFailedStatusCode: 400)] WebhookListQuery $query = new WebhookListQuery()): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);
        $slice = $this->log->slice($id, Cursor::decode($query->cursor), $query->limit);

        return new JsonResponse(['data' => array_map(DeliveryView::row(...), $slice->rows), 'next_cursor' => Cursor::next($slice->hasMore ? $slice->lastId() : null)]);
    }

    #[Route('/api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\Response(response: 200, description: 'The delivery with its attempts, oldest first.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WebhookDeliveryDetail')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope, or the endpoint subscribes to events its person may not read.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such endpoint, or the endpoint no such delivery.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $id, string $deliveryId): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);

        return new JsonResponse(['data' => DeliveryView::detail($this->log->delivery($id, $deliveryId))]);
    }

    #[Route('/api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}/redeliver', name: self::REDELIVER, methods: ['POST'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\Response(response: 202, description: 'One more attempt is queued, with the same webhook-id and a new signature. Its outcome shows in the attempts.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WebhookQueuedAttempt')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the write scope, or the endpoint subscribes to events its person may not read.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such endpoint, or the endpoint no such delivery.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 409, description: 'The endpoint is disabled, the delivery is still on its schedule, or it is a ping.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 503, description: 'The installation cannot sign webhooks yet.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function redeliver(string $id, string $deliveryId): JsonResponse
    {
        $this->scope->require(ScopeGuard::WRITE);
        $queued = $this->manage->redeliver($id, $deliveryId);

        return new JsonResponse(['data' => ['delivery_id' => $queued->deliveryId, 'attempt_id' => $queued->attemptId]], Response::HTTP_ACCEPTED);
    }
}
