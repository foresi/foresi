<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api;

use Foresi\Sales\Application\DealReads;
use Foresi\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sales read by a personal API token with the sales:read scope: the deals,
 * narrowed by status, stage, owner, client and text, one deal with its lines,
 * value, links and contact persons, and the stages and lost reasons. What the
 * use cases refuse is answered by the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class SalesApiController
{
    public const string READ_SCOPE = 'sales:read';
    public const string DEALS = 'api_v1_sales_deals';
    public const string DEAL = 'api_v1_sales_deal';
    public const string STAGES = 'api_v1_sales_stages';
    public const string LOST_REASONS = 'api_v1_sales_lost_reasons';

    public function __construct(private ScopeGuard $scope, private DealReads $reads, private SalesJson $json)
    {
    }

    #[Route('/api/v1/sales/deals', name: self::DEALS, methods: ['GET'])]
    #[OA\Tag(name: 'Sales')]
    #[OA\Parameter(name: 'status', description: 'Only the deals of this status.', in: 'query', schema: new OA\Schema(type: 'string', enum: ['open', 'won', 'lost']))]
    #[OA\Parameter(name: 'stage_id', description: 'Only the deals at this stage.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'owner_user_id', description: 'Only the deals this owner or member works on.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'client_party_id', description: 'Only the deals of this client, a contact of the address book.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'q', description: 'Only the deals whose title holds this text, without regard to case.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: DealReads::MAX_TEXT))]
    #[OA\Response(response: 200, description: 'The deals not in the trash, the newest first.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesDealSummary'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'The status is not open, won or lost.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the sales:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function deals(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $deals = $this->reads->deals(
            $this->text($request, 'status'),
            $this->text($request, 'stage_id'),
            $this->text($request, 'owner_user_id'),
            $this->text($request, 'client_party_id'),
            $this->text($request, 'q'),
        );

        return new JsonResponse(['data' => $this->json->summaries($deals)]);
    }

    #[Route('/api/v1/sales/deals/{dealId}', name: self::DEAL, methods: ['GET'])]
    #[OA\Tag(name: 'Sales')]
    #[OA\Response(response: 200, description: 'The deal with its lines, value, links and contact persons, also one in the trash.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SalesDeal')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the sales:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'There is no such deal.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function deal(string $dealId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => $this->json->deal($this->reads->deal($dealId))]);
    }

    #[Route('/api/v1/sales/stages', name: self::STAGES, methods: ['GET'])]
    #[OA\Tag(name: 'Sales')]
    #[OA\Parameter(name: 'include_archived', description: 'true includes the archived stages, after the others.', in: 'query', schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(response: 200, description: 'The stages of a deal, in their order.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesStage'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the sales:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function stages(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => array_map($this->json->stage(...), $this->reads->stages($request->query->getBoolean('include_archived')))]);
    }

    #[Route('/api/v1/sales/lost-reasons', name: self::LOST_REASONS, methods: ['GET'])]
    #[OA\Tag(name: 'Sales')]
    #[OA\Parameter(name: 'include_archived', description: 'true includes the archived lost reasons, after the others.', in: 'query', schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(response: 200, description: 'The reasons a deal is lost for, in their order.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesLostReason'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the sales:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function lostReasons(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => array_map($this->json->lostReason(...), $this->reads->lostReasons($request->query->getBoolean('include_archived')))]);
    }

    private function text(Request $request, string $name): ?string
    {
        $value = trim($request->query->getString($name));

        return '' === $value ? null : $value;
    }
}
