<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api;

use Foresi\Invoicing\Application\Query\InvoiceDetails;
use Foresi\Invoicing\Application\Query\InvoiceList;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The sales documents of the organisation, read by a personal API token with the
 * invoices:read scope. The query services decide who may read; what they refuse is
 * answered by InvoicingErrors. A saved view or a filter narrows the list (spec D13);
 * what reading them refuses is answered by the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class InvoiceApiController
{
    public const string READ_SCOPE = 'invoices:read';
    public const string LIST = 'api_v1_invoices';
    public const string SHOW = 'api_v1_invoice';

    public function __construct(private ScopeGuard $scope, private InvoiceList $list, private InvoiceDetails $details, private SavedViews $views, private ViewJson $json)
    {
    }

    #[Route('/api/v1/invoices', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Invoices')]
    #[OA\Response(response: 200, description: 'The documents, newest first, the cursor of the next slice and the saved view applied.', content: new OA\JsonContent(ref: '#/components/schemas/InvoicePage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used, such as a filter with an unknown field; parameter names its path, such as filter.conditions[0].op.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the invoices:read scope, or views:read for a view.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No saved view with this id the token\'s person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 409, description: 'The saved view filters on what its list no longer has.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] InvoiceListQuery $query): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $applied = InvoiceViewFilter::of($this->views, $this->scope, $query->kind, $query->view, $query->filter);
        $slice = $this->list->slice($query->filter($applied->filter, $applied->kind), Cursor::decode($query->cursor), $query->limit);

        return new JsonResponse([
            'data' => array_map(InvoiceJson::item(...), $slice->items),
            'next_cursor' => Cursor::next($slice->nextAfterId()),
            'applied_view' => $applied->view instanceof \Foresi\Shared\Domain\View\SavedView ? $this->json->view($applied->view) : null,
        ]);
    }

    #[Route('/api/v1/invoices/{invoiceId}', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Invoices')]
    #[OA\Response(response: 200, description: 'The document with its lines, payments, corrections and balances.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/InvoiceDetail')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the invoices:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such document.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $invoiceId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => InvoiceJson::detail($this->details->of($invoiceId))]);
    }
}
