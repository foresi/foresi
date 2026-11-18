<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\View\ListDefinitions;
use Foresi\Shared\Application\View\ListRecordCounters;
use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Domain\View\SavedView;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The lists saved views filter and the views a person sees, read by a personal
 * API token with the views:read scope (spec D13). Counting a view's records also
 * needs the scope of its list, such as invoices:read. What the use cases refuse
 * is answered by the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class ViewApiController
{
    public const string READ_SCOPE = 'views:read';
    public const string LISTS = 'api_v1_lists';
    public const string VIEWS = 'api_v1_views';
    public const string VIEW = 'api_v1_view';
    public const string COUNT = 'api_v1_view_count';

    public function __construct(
        private ScopeGuard $scope,
        private ListDefinitions $lists,
        private SavedViews $views,
        private ListRecordCounters $counters,
        private ViewJson $json,
    ) {
    }

    #[Route('/api/v1/lists', name: self::LISTS, methods: ['GET'])]
    #[OA\Tag(name: 'Saved views')]
    #[OA\Response(response: 200, description: 'Every list saved views filter: its fields with their operators and values, and its columns; and the date values every date field takes.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ViewList')), new OA\Property(property: 'date_values', type: 'object')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the views:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function lists(): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => array_map($this->json->list(...), array_values($this->lists->all())), 'date_values' => ViewJson::dateValues()]);
    }

    #[Route('/api/v1/views', name: self::VIEWS, methods: ['GET'])]
    #[OA\Tag(name: 'Saved views')]
    #[OA\Parameter(name: 'list', description: 'The list, such as invoicing.invoices.', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'The views of the list the token\'s person sees, shared and private, hidden ones too, in their order.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SavedView'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'No list, or one no module declares.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the views:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function views(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $list = trim($request->query->getString('list'));
        if ('' === $list) {
            throw SavedViewRefused::invalid('list_required', 'list', 'list names the list, such as invoicing.invoices; find the lists with GET /api/v1/lists.');
        }

        return new JsonResponse(['data' => array_map($this->json->view(...), $this->views->visible($list))]);
    }

    #[Route('/api/v1/views/{viewId}', name: self::VIEW, methods: ['GET'])]
    #[OA\Tag(name: 'Saved views')]
    #[OA\Parameter(name: 'viewId', description: 'The id of the view, or the system key of a view a module ships, such as invoicing.invoices.overdue.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'The view.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SavedView')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the views:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No view with this id the token\'s person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function view(string $viewId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => $this->json->view($this->views->get($viewId))]);
    }

    #[Route('/api/v1/views/{viewId}/count', name: self::COUNT, methods: ['GET'])]
    #[OA\Tag(name: 'Saved views')]
    #[OA\Parameter(name: 'viewId', description: 'The id of the view, or the system key of a view a module ships.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'How many records the view holds now.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'view_id', type: 'string'), new OA\Property(property: 'list', type: 'string'), new OA\Property(property: 'count', type: 'integer')], type: 'object')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks views:read or the read scope of the view\'s list, such as invoices:read.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No view with this id the token\'s person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 409, description: 'The view filters on what its list no longer has (view_outdated), or no installed module counts its list (count_unavailable).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function count(string $viewId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $view = $this->views->get($viewId);

        return new JsonResponse(['data' => ['view_id' => $view->id(), 'list' => $view->listKey(), 'count' => $this->counted($view)]]);
    }

    private function counted(SavedView $view): int
    {
        $counter = $this->counters->of($view->listKey()) ?? throw SavedViewRefused::conflict('count_unavailable', \sprintf('No installed module counts the list "%s".', $view->listKey()));
        $this->scope->require($counter->scope());

        return $counter->count($view->listKey(), $this->views->filterOf($view));
    }
}
