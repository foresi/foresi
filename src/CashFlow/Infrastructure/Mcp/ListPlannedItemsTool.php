<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Mcp;

use App\CashFlow\Application\CashFlowRefused;
use App\CashFlow\Application\PlannedItemReads;
use App\CashFlow\Domain\PlannedItem;
use App\CashFlow\Infrastructure\Api\CashFlowJson;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Tenancy\Application\TenantClock;

/**
 * The planned items of a firm, as GET /api/v1/cash-flow/planned-items writes them.
 */
final readonly class ListPlannedItemsTool implements ReadTool
{
    public function __construct(private PlannedItemReads $items, private TenantClock $clock)
    {
    }

    public function name(): string
    {
        return 'foresi_list_planned_items';
    }

    public function title(): string
    {
        return 'List planned items';
    }

    public function scope(): string
    {
        return 'cashflow:read';
    }

    public function description(): string
    {
        return 'Returns the planned items of a firm, oldest first: {items} with the id, direction, name, category, amount, day or schedule, skipped days, whether the forecast includes it and its match rule. '
            .'A firm has few, so there is no paging. Use an id with foresi_change_planned_item, foresi_skip_planned_occurrence, foresi_end_planned_item or foresi_delete_planned_item.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['firm_id' => ToolArguments::idSchema('The id of the firm, as foresi_list_firms returns it.')],
            'required' => ['firm_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $items = $this->items->of(ToolArguments::text($arguments, 'firm_id') ?? '');
        } catch (CashFlowRefused $refused) {
            throw CashFlowToolRefusal::of($refused);
        }
        $zone = $this->clock->timezone();
        $count = \count($items);

        return new ToolOutput(
            ['items' => array_map(static fn (PlannedItem $item): array => CashFlowJson::plannedItem($item, $zone), $items)],
            1 === $count ? '1 planned item.' : \sprintf('%d planned items, oldest first.', $count),
        );
    }
}
