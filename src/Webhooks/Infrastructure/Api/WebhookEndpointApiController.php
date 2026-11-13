<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Api;

use App\Shared\Application\Api\ScopeGuard;
use App\Shared\Infrastructure\Api\Cursor;
use App\Shared\Infrastructure\Api\JsonValues;
use App\Webhooks\Application\ManageWebhookEndpoints;
use App\Webhooks\Application\Query\WebhookEndpointList;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The webhook endpoints of the organisation: listed and shown with the read
 * scope, registered, changed, deleted, rotated, revealed and pinged with the
 * write scope. The use cases decide who may manage which endpoint; what they
 * refuse is answered by WebhookErrors. Only create, rotate and reveal answer
 * with a secret.
 */
#[AsController]
final readonly class WebhookEndpointApiController
{
    public const string LIST = 'api_v1_webhook_endpoints';
    public const string CREATE = 'api_v1_webhook_endpoint_create';
    public const string SHOW = 'api_v1_webhook_endpoint';
    public const string UPDATE = 'api_v1_webhook_endpoint_update';
    public const string DELETE = 'api_v1_webhook_endpoint_delete';
    public const string ROTATE = 'api_v1_webhook_endpoint_rotate';
    public const string SECRET = 'api_v1_webhook_endpoint_secret';
    public const string PING = 'api_v1_webhook_endpoint_ping';

    public function __construct(
        private ScopeGuard $scope,
        private WebhookEndpointList $list,
        private ManageWebhookEndpoints $manage,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/api/v1/webhook-endpoints', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\Response(response: 200, description: 'The endpoints the token\'s person may manage, newest first, and the cursor of the next slice.', content: new OA\JsonContent(ref: '#/components/schemas/WebhookEndpointPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] WebhookListQuery $query = new WebhookListQuery()): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);
        $slice = $this->list->slice(Cursor::decode($query->cursor), $query->limit);

        return new JsonResponse(['data' => array_map(EndpointView::of(...), $slice->endpoints), 'next_cursor' => Cursor::next($slice->hasMore ? $slice->lastId() : null)]);
    }

    #[Route('/api/v1/webhook-endpoints', name: self::CREATE, methods: ['POST'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WebhookEndpointCreate'))]
    #[OA\Response(response: 201, description: 'The endpoint with its signing secret, shown here and by the secret endpoint only. A ping is queued.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WebhookEndpointWithSecret')], type: 'object'))]
    #[OA\Response(response: 400, description: 'A field cannot be used: a URL that is not https or points to a private network, an unknown event type, or "*".', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the write scope, or its person may not read the events of a type asked for.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 503, description: 'The installation has no key to seal webhook secrets yet.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function create(#[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)] CreateEndpointRequest $body): JsonResponse
    {
        $this->scope->require(ScopeGuard::WRITE);
        $created = $this->manage->create((string) $body->url, $body->description, $body->eventTypes ?? [], $body->version);
        $id = $created->endpoint->id();

        return new JsonResponse(
            ['data' => EndpointView::of($created->endpoint) + ['secret' => $created->secret->reveal()]],
            Response::HTTP_CREATED,
            ['Location' => $this->urls->generate(self::SHOW, ['id' => $id])],
        );
    }

    #[Route('/api/v1/webhook-endpoints/{id}', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\Response(response: 200, description: 'The endpoint, without its secret.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WebhookEndpoint')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope, or the endpoint subscribes to events its person may not read.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such endpoint.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $id): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);

        return new JsonResponse(['data' => EndpointView::of($this->list->show($id))]);
    }

    #[Route('/api/v1/webhook-endpoints/{id}', name: self::UPDATE, methods: ['PATCH'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WebhookEndpointUpdate'))]
    #[OA\Response(response: 200, description: 'The endpoint as it is now.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WebhookEndpoint')], type: 'object'))]
    #[OA\Response(response: 400, description: 'A field cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the write scope, or its person may not read the events the endpoint subscribes to.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such endpoint.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function update(string $id, #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)] UpdateEndpointRequest $body, Request $request): JsonResponse
    {
        $this->scope->require(ScopeGuard::WRITE);
        $endpoint = $this->manage->update(
            $id,
            $body->url,
            $body->description($request->getPayload()->all()),
            $body->eventTypes,
            $body->version,
            $body->active,
        );

        return new JsonResponse(['data' => EndpointView::of($endpoint)]);
    }

    #[Route('/api/v1/webhook-endpoints/{id}', name: self::DELETE, methods: ['DELETE'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\Response(response: 204, description: 'The endpoint is deleted, with its deliveries and their attempts.')]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the write scope, or its person may not read the events the endpoint subscribes to.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such endpoint.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function delete(string $id): Response
    {
        $this->scope->require(ScopeGuard::WRITE);
        $this->manage->delete($id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v1/webhook-endpoints/{id}/secret/rotate', name: self::ROTATE, methods: ['POST'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'keep_previous_hours', description: 'How long the previous secret still signs, 0 to 168 hours. Default 24.', type: 'integer')], type: 'object'))]
    #[OA\Response(response: 200, description: 'The new secret, and until when the previous one still signs.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WebhookSecret')], type: 'object'))]
    #[OA\Response(response: 400, description: 'The hours are out of range.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the write scope, or its person may not read the events the endpoint subscribes to.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such endpoint.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 503, description: 'The installation has no key to seal webhook secrets yet.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function rotate(string $id, #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)] RotateSecretRequest $body = new RotateSecretRequest()): JsonResponse
    {
        $this->scope->require(ScopeGuard::WRITE);
        $secret = $this->manage->rotate($id, $body->keepPreviousHours);

        return new JsonResponse(['data' => $this->secret($id, $secret->reveal())]);
    }

    #[Route('/api/v1/webhook-endpoints/{id}/secret', name: self::SECRET, methods: ['GET'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\Response(response: 200, description: 'The secret the endpoint is signed with now.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WebhookSecret')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the write scope, or its person may not read the events the endpoint subscribes to.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such endpoint.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 503, description: 'The installation cannot open webhook secrets.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function reveal(string $id): JsonResponse
    {
        // Reading the secret is as good as signing with it, so it takes the write scope.
        $this->scope->require(ScopeGuard::WRITE);

        return new JsonResponse(['data' => $this->secret($id, $this->manage->reveal($id)->reveal())]);
    }

    #[Route('/api/v1/webhook-endpoints/{id}/ping', name: self::PING, methods: ['POST'])]
    #[OA\Tag(name: 'Webhooks')]
    #[OA\Response(response: 202, description: 'A ping is queued: one attempt, no retries. Its outcome shows in the deliveries.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WebhookQueuedAttempt')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the write scope, or its person may not read the events the endpoint subscribes to.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such endpoint.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 409, description: 'The endpoint is disabled.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 503, description: 'The installation cannot sign webhooks yet.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function ping(string $id): JsonResponse
    {
        $this->scope->require(ScopeGuard::WRITE);
        $queued = $this->manage->ping($id);

        return new JsonResponse(['data' => ['delivery_id' => $queued->deliveryId, 'attempt_id' => $queued->attemptId]], Response::HTTP_ACCEPTED);
    }

    /**
     * @return array{secret: string, previous_secret_expires_at: string|null}
     */
    private function secret(string $id, string $secret): array
    {
        return ['secret' => $secret, 'previous_secret_expires_at' => JsonValues::timestamp($this->list->show($id)->previousExpiresAt())];
    }
}
