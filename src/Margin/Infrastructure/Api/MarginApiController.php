<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api;

use Foresi\Margin\Application\AllocationReads;
use Foresi\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The margin of a document read by a personal API token with the margin:read
 * scope, by the owners and the members alike. What the use cases refuse is
 * answered by the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class MarginApiController
{
    public const string READ_SCOPE = 'margin:read';
    public const string DOCUMENT = 'api_v1_margin_document';
    public const string PLANNED_ITEM = 'api_v1_margin_planned_item_allocation';
    public const string SUPPLIER = 'api_v1_margin_supplier_default';

    public function __construct(private ScopeGuard $scope, private AllocationReads $reads)
    {
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
}
