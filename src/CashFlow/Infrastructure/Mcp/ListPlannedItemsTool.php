<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Mcp;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\PlannedItemReads;
use Foresi\CashFlow\Application\PlannedItemVatRule;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Infrastructure\Api\CashFlowJson;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;

/**
 * The planned items of the firm, as GET /api/v1/cash-flow/planned-items writes them.
 */
final readonly class ListPlannedItemsTool implements ReadTool
{
    public function __construct(private PlannedItemReads $items, private InstanceClock $clock, private CashFlowAccess $access, private PlannedItemVatRule $vat)
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
        return 'Returns the planned items of the firm, oldest first: {items} with the id, direction, name, category, amount, day or schedule, skipped days, whether the forecast includes it, its match rule, and its VAT: vat_mode, vat_rate, the net and vat of one occurrence, and vat_missing, true while the firm is a VAT payer and the item states no VAT. '
            .'There are few, so there is no paging. Use an id with foresi_change_planned_item, foresi_skip_planned_occurrence, foresi_end_planned_item or foresi_delete_planned_item.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $items = $this->items->all();
            $firm = $this->access->firm();
        } catch (CashFlowRefused $refused) {
            throw CashFlowToolRefusal::of($refused);
        }
        $zone = $this->clock->timezone();
        $count = \count($items);

        return new ToolOutput(
            ['items' => array_map(fn (PlannedItem $item): array => CashFlowJson::plannedItem($item, $zone, $firm, $this->vat->isMissing($item)), $items)],
            1 === $count ? '1 planned item.' : \sprintf('%d planned items, oldest first.', $count),
        );
    }
}
