<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Api;

use App\Invoicing\Application\Query\PaymentDetails;
use App\Invoicing\Application\Query\PaymentList;
use App\Shared\Application\Api\ScopeGuard;
use App\Shared\Infrastructure\Api\Cursor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The payments of the organisation's invoices, read by a personal API token with
 * the read scope. The query services decide who may read; what they refuse is
 * answered by InvoicingErrors.
 */
#[AsController]
final readonly class PaymentApiController
{
    public const string LIST = 'api_v1_payments';
    public const string SHOW = 'api_v1_payment';

    public function __construct(private ScopeGuard $scope, private PaymentList $list, private PaymentDetails $details)
    {
    }

    #[Route('/api/v1/payments', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Payments')]
    #[OA\Response(response: 200, description: 'The payments, newest first, and the cursor of the next slice.', content: new OA\JsonContent(ref: '#/components/schemas/PaymentPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] PaymentListQuery $query): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);
        $slice = $this->list->slice($query->filter(), Cursor::decode($query->cursor), $query->limit);

        return new JsonResponse(['data' => array_map(PaymentJson::row(...), $slice->rows), 'next_cursor' => Cursor::next($slice->hasMore ? $slice->lastId() : null)]);
    }

    #[Route('/api/v1/payments/{id}', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Payments')]
    #[OA\Response(response: 200, description: 'The payment with the invoices it pays.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Payment')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such payment.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $id): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);

        return new JsonResponse(['data' => PaymentJson::row($this->details->of($id))]);
    }
}
