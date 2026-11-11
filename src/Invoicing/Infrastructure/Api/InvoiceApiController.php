<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Api;

use App\Invoicing\Application\Query\InvoiceDetails;
use App\Invoicing\Application\Query\InvoiceList;
use App\Shared\Application\Api\ScopeGuard;
use App\Shared\Infrastructure\Api\Cursor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The sales documents of the organisation, read by a personal API token with the
 * read scope. The query services decide who may read; what they refuse is
 * answered by InvoicingErrors.
 */
#[AsController]
final readonly class InvoiceApiController
{
    public const string LIST = 'api_v1_invoices';
    public const string SHOW = 'api_v1_invoice';

    public function __construct(private ScopeGuard $scope, private InvoiceList $list, private InvoiceDetails $details)
    {
    }

    #[Route('/api/v1/invoices', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Invoices')]
    #[OA\Response(response: 200, description: 'The documents, newest first, and the cursor of the next slice.', content: new OA\JsonContent(ref: '#/components/schemas/InvoicePage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] InvoiceListQuery $query): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);
        $slice = $this->list->slice($query->filter(), Cursor::decode($query->cursor), $query->limit);

        return new JsonResponse(['data' => array_map(InvoiceJson::item(...), $slice->items), 'next_cursor' => Cursor::next($slice->nextAfterId())]);
    }

    #[Route('/api/v1/invoices/{id}', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Invoices')]
    #[OA\Response(response: 200, description: 'The document with its lines, payments, corrections and balances.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/InvoiceDetail')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such document.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $id): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);

        return new JsonResponse(['data' => InvoiceJson::detail($this->details->of($id))]);
    }
}
