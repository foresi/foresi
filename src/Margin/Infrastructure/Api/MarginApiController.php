<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api;

use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Application\MarginCell;
use Foresi\Margin\Application\MarginTable;
use Foresi\Margin\Application\Unrebilled;
use Foresi\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The margin of the clients and of a document read by a personal API token with the margin:read
 * scope, by the owners and the members alike. What the use cases refuse is
 * answered by the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class MarginApiController
{
    public const string READ_SCOPE = 'margin:read';
    public const string TABLE = 'api_v1_margin_table';
    public const string CELL = 'api_v1_margin_cell';
    public const string DOCUMENT = 'api_v1_margin_document';
    public const string PLANNED_ITEM = 'api_v1_margin_planned_item_allocation';
    public const string SUPPLIER = 'api_v1_margin_supplier_default';
    public const string UNREBILLED = 'api_v1_margin_unrebilled';

    public function __construct(private ScopeGuard $scope, private AllocationReads $reads, private MarginTable $table, private MarginCell $cell, private Unrebilled $unrebilled)
    {
    }

    #[Route('/api/v1/margin', name: self::TABLE, methods: ['GET'])]
    #[OA\Tag(name: 'Margin')]
    #[OA\Parameter(name: 'from', description: 'The first month, YYYY-MM; two months before the last one when left out.', in: 'query', schema: new OA\Schema(type: 'string', example: '2026-10'))]
    #[OA\Parameter(name: 'to', description: 'The last month, YYYY-MM; the current month when left out. A range covers at most 24 months.', in: 'query', schema: new OA\Schema(type: 'string', example: '2026-12'))]
    #[OA\Response(response: 200, description: 'Revenue, costs and margin in CZK of every client, of the firm itself and of what nobody claims, by month.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarginTable')], type: 'object'))]
    #[OA\Response(response: 400, description: 'A month cannot be used (invalid_month, range_reversed or range_too_long).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the margin:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function table(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => MarginJson::table($this->table->of($this->optional($request, 'from'), $this->optional($request, 'to')))]);
    }

    #[Route('/api/v1/margin/cell', name: self::CELL, methods: ['GET'])]
    #[OA\Tag(name: 'Margin')]
    #[OA\Parameter(name: 'row', description: 'client:<contact id>, own or unassigned.', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: 'own'))]
    #[OA\Parameter(name: 'month', description: 'The month, YYYY-MM.', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: '2026-11'))]
    #[OA\Response(response: 200, description: 'The revenue documents and the cost shares behind one cell, each with its label, amount, source and url.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarginCell')], type: 'object'))]
    #[OA\Response(response: 400, description: 'The row or the month cannot be used (invalid_row or invalid_month).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the margin:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function cell(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => MarginJson::cellDetail($this->cell->of($request->query->getString('row'), $request->query->getString('month')))]);
    }

    #[Route('/api/v1/margin/unrebilled', name: self::UNREBILLED, methods: ['GET'])]
    #[OA\Tag(name: 'Margin')]
    #[OA\Parameter(name: 'from', description: 'The first month, YYYY-MM; two months before the last one when left out.', in: 'query', schema: new OA\Schema(type: 'string', example: '2026-10'))]
    #[OA\Parameter(name: 'to', description: 'The last month, YYYY-MM; the current month when left out. A range covers at most 24 months.', in: 'query', schema: new OA\Schema(type: 'string', example: '2026-12'))]
    #[OA\Response(response: 200, description: 'The occurrences of planned costs that were paid and not rebilled to a client whose recurring invoice rebills them, earliest first.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarginUnrebilled'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'A month cannot be used (invalid_month, range_reversed or range_too_long).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the margin:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function unrebilled(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => MarginJson::unrebilled($this->unrebilled->between($this->optional($request, 'from'), $this->optional($request, 'to')))]);
    }

    #[Route('/api/v1/margin/documents/{documentId}', name: self::DOCUMENT, methods: ['GET'])]
    #[OA\Tag(name: 'Margin')]
    #[OA\Parameter(name: 'documentId', description: 'The id of the received document.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'The document with each effective line, its own mapping, its shares and where each share came from, the mapping of the whole document and the known gaps.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarginDocumentAllocation')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the margin:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No recorded document with this id has costs (unknown_document).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function document(string $documentId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => MarginJson::document($this->reads->document($documentId))]);
    }

    #[Route('/api/v1/margin/planned-items/{plannedItemId}/allocation', name: self::PLANNED_ITEM, methods: ['GET'])]
    #[OA\Tag(name: 'Margin')]
    #[OA\Parameter(name: 'plannedItemId', description: 'The id of the planned item.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'The versions of the mapping of the planned item, latest first.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarginPlannedAllocations')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the margin:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'Cash flow has no planned item with this id, or is not installed (unknown_planned_item).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function plannedItem(string $plannedItemId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => MarginJson::planned($this->reads->planned($plannedItemId))]);
    }

    #[Route('/api/v1/margin/suppliers/{contactId}/default-recipient', name: self::SUPPLIER, methods: ['GET'])]
    #[OA\Tag(name: 'Margin')]
    #[OA\Parameter(name: 'contactId', description: 'The id of the supplier, a contact.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'The versions of the default recipient of the supplier, latest first.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarginSupplierDefaults')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the margin:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no contact with this id (unknown_supplier).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function supplierDefault(string $contactId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => MarginJson::supplierDefault($this->reads->supplierDefault($contactId))]);
    }

    private function optional(Request $request, string $name): ?string
    {
        $value = $request->query->getString($name);

        return '' === $value ? null : $value;
    }
}
