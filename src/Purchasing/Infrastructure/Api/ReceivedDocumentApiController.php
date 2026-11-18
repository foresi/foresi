<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api;

use Foresi\Purchasing\Application\Query\ReceivedDocumentBalances;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Api\Cursor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The received documents of the organisation with what is paid and what
 * remains, read by a personal API token with the received_documents:read
 * scope. The query services decide who may read; what they refuse is answered
 * by PurchasingErrors.
 */
#[AsController]
final readonly class ReceivedDocumentApiController
{
    public const string READ_SCOPE = 'received_documents:read';
    public const string LIST = 'api_v1_received_documents';
    public const string SHOW = 'api_v1_received_document';
    public const string PAYMENTS = 'api_v1_received_document_payments';

    public function __construct(private ScopeGuard $scope, private ReceivedDocumentBalances $balances)
    {
    }

    #[Route('/api/v1/received-documents', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Received documents')]
    #[OA\Response(response: 200, description: 'The received documents, newest first, and the cursor of the next slice.', content: new OA\JsonContent(ref: '#/components/schemas/ReceivedDocumentPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the received_documents:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] ReceivedDocumentListQuery $query): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $slice = $this->balances->slice($query->filter(), Cursor::decode($query->cursor), $query->limit);

        return new JsonResponse(['data' => array_map(ReceivedDocumentJson::row(...), $slice->items), 'next_cursor' => Cursor::next($slice->hasMore ? $slice->lastId() : null)]);
    }

    #[Route('/api/v1/received-documents/{documentId}', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Received documents')]
    #[OA\Response(response: 200, description: 'The received document with its VAT recap and payments.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReceivedDocumentDetail')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the received_documents:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such received document.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $documentId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => ReceivedDocumentJson::detail($this->balances->of($documentId))]);
    }

    #[Route('/api/v1/received-documents/{documentId}/payments', name: self::PAYMENTS, methods: ['GET'])]
    #[OA\Tag(name: 'Received documents')]
    #[OA\Response(response: 200, description: 'The payments of the received document, by the day paid.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReceivedDocumentPayment'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the received_documents:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such received document.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function payments(string $documentId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => array_map(ReceivedDocumentJson::payment(...), $this->balances->of($documentId)->payments)]);
    }
}
