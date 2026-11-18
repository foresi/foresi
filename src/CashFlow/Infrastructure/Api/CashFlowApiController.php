<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Api;

use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Application\PlannedItemReads;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The cash flow read by a personal API token with the cashflow:read scope:
 * the forecast of a firm and its planned items. What the use cases refuse is
 * answered by the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class CashFlowApiController
{
    public const string READ_SCOPE = 'cashflow:read';
    public const string FORECAST = 'api_v1_cash_flow_forecast';
    public const string PLANNED_ITEMS = 'api_v1_cash_flow_planned_items';
    public const string PLANNED_ITEM = 'api_v1_cash_flow_planned_item';

    public function __construct(private ScopeGuard $scope, private Forecasts $forecasts, private PlannedItemReads $items, private InstanceClock $clock)
    {
    }

    #[Route('/api/v1/cash-flow/forecast', name: self::FORECAST, methods: ['GET'])]
    #[OA\Tag(name: 'Cash flow')]
    #[OA\Parameter(name: 'firm_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'from', description: 'The first day, today or later; today when left out.', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'months', description: 'How many calendar months the forecast covers, 1 to 24; 12 when left out.', in: 'query', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'weeks_until', description: 'Weeks are used up to the week that holds this day, then months; the end of next month when left out.', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'The forecast of the firm in CZK.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CashFlowForecast')], type: 'object'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the cashflow:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such firm.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function forecast(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $query = $request->query;
        $months = $query->getString('months');
        if ('' !== $months && 1 !== preg_match('/^[0-9]{1,3}\z/', $months)) {
            throw CashFlowRefused::invalid('months_invalid', 'months', 'months is a whole number of months, 1 to 24.');
        }
        $forecast = $this->forecasts->of($this->firm($request), $this->optional($request, 'from'), '' === $months ? null : (int) $months, $this->optional($request, 'weeks_until'));

        return new JsonResponse(['data' => CashFlowJson::forecast($forecast)]);
    }

    #[Route('/api/v1/cash-flow/planned-items', name: self::PLANNED_ITEMS, methods: ['GET'])]
    #[OA\Tag(name: 'Cash flow')]
    #[OA\Parameter(name: 'firm_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'The planned items of the firm, oldest first.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CashFlowPlannedItem'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the cashflow:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such firm.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function plannedItems(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $zone = $this->clock->timezone();

        return new JsonResponse(['data' => array_map(static fn (PlannedItem $item): array => CashFlowJson::plannedItem($item, $zone), $this->items->of($this->firm($request)))]);
    }

    #[Route('/api/v1/cash-flow/planned-items/{plannedItemId}', name: self::PLANNED_ITEM, methods: ['GET'])]
    #[OA\Tag(name: 'Cash flow')]
    #[OA\Response(response: 200, description: 'The planned item.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CashFlowPlannedItem')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the cashflow:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such planned item.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function plannedItem(string $plannedItemId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => CashFlowJson::plannedItem($this->items->one($plannedItemId), $this->clock->timezone())]);
    }

    private function firm(Request $request): string
    {
        return $this->optional($request, 'firm_id') ?? throw CashFlowRefused::invalid('firm_required', 'firmId', 'firm_id names the firm; find it with the MCP tool foresi_list_firms.');
    }

    private function optional(Request $request, string $name): ?string
    {
        $value = trim($request->query->getString($name));

        return '' === $value ? null : $value;
    }
}
