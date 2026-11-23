<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api;

use Foresi\Invoicing\Application\Api\InvoicingRefused;
use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Operation\InvalidInput;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Recurring invoices read over REST, and a schedule previewed without saving.
 * The writes are operations (CreateRecurringInvoiceInput and the rest). What is
 * refused is answered by OperationErrors.
 */
final readonly class RecurringInvoiceApiController
{
    public const string READ_SCOPE = 'invoices:read';
    public const string LIST = 'api_v1_recurring_invoices';
    public const string SHOW = 'api_v1_recurring_invoice';
    public const string PREVIEW = 'api_v1_recurring_invoice_preview';

    public function __construct(private ScopeGuard $scope, private RecurringWrites $writes, private RecurringInvoiceJson $json, private RecurringInvoicePreviews $previews)
    {
    }

    #[Route('/api/v1/recurring-invoices', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Recurring invoices')]
    #[OA\Parameter(name: 'state', description: 'Only the templates in this state.', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'paused', 'ended']))]
    #[OA\Parameter(name: 'page', description: 'The page, from 1; 25 templates a page.', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Response(response: 200, description: 'The templates out of the trash, the soonest next issue first, ended ones last.', content: new OA\JsonContent(ref: '#/components/schemas/RecurringInvoicePage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the invoices:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $state = $request->query->getString('state');
        $known = RecurringState::tryFrom($state);
        if ('' !== $state && null === $known) {
            throw InvoicingRefused::unreadable('state_invalid', 'state', 'The state is active, paused or ended.');
        }
        $page = $this->writes->page($known, max(1, $request->query->getInt('page', 1)));

        return new JsonResponse([
            'data' => array_map(RecurringInvoiceJson::item(...), $page->templates),
            'page' => $page->page,
            'pages' => $page->pages,
            'total' => $page->total,
        ]);
    }

    #[Route('/api/v1/recurring-invoices/{recurringInvoiceId}', name: self::SHOW, requirements: ['recurringInvoiceId' => Requirement::UUID], methods: ['GET'])]
    #[OA\Tag(name: 'Recurring invoices')]
    #[OA\Response(response: 200, description: 'The template with its schedule, delivery, lines and next issues.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RecurringInvoiceDetail')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the invoices:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such recurring invoice.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $recurringInvoiceId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => $this->json->detail($this->writes->template($recurringInvoiceId))]);
    }

    #[Route('/api/v1/recurring-invoices/preview', name: self::PREVIEW, methods: ['POST'], priority: 1)]
    #[OA\Tag(name: 'Recurring invoices')]
    #[OA\RequestBody(description: 'The fields of POST /api/v1/recurring-invoices, and count: how many next issues to show (5 by default, at most 24).', content: new OA\JsonContent(type: 'object'))]
    #[OA\Response(response: 200, description: 'The template a create would save and its next issues; nothing is saved. saves is false with the problems the rules would refuse.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RecurringInvoicePreview')], type: 'object'))]
    #[OA\Response(response: 400, description: 'A field cannot be read.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the invoices:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no invoice of from_invoice_id.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function preview(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        try {
            $sent = '' === $request->getContent() ? [] : json_decode($request->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidInput('body', 'must be a JSON object.');
        }
        if (!\is_array($sent) || (array_is_list($sent) && [] !== $sent)) {
            throw new InvalidInput('body', 'must be a JSON object.');
        }

        return new JsonResponse(['data' => $this->previews->of($sent)]);
    }
}
