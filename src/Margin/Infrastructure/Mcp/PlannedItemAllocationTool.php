<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Mcp;

use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * The versions of a planned item's mapping, as GET /api/v1/margin/planned-items/{plannedItemId}/allocation writes them.
 */
final readonly class PlannedItemAllocationTool implements ReadTool
{
    public function __construct(private AllocationReads $reads)
    {
    }

    public function name(): string
    {
        return 'foresi_get_planned_item_allocation';
    }

    public function title(): string
    {
        return 'Get the mapping of a planned cost';
    }

    public function scope(): string
    {
        return 'margin:read';
    }

    public function description(): string
    {
        return 'Returns who bears the cost of a recurring planned cost in Cash flow: its versions, latest first, each with from_month (YYYY-MM), its allocation (kind and shares, or null where the mapping ends), current (the version in force this month) and who set it when. '
            .'A mapping applies from its month on to the documents paired with the item\'s occurrences. Set it with foresi_set_planned_item_allocation. Find the planned item with foresi_list_planned_items.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['planned_item_id' => ToolSchema::idSchema('The id of the planned item, as foresi_list_planned_items returns it.')],
            'required' => ['planned_item_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $view = $this->reads->planned(ToolSchema::text($arguments, 'planned_item_id') ?? '');
        } catch (MarginRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(MarginJson::planned($view), \sprintf('%d versions of the mapping of the planned item.', \count($view->versions)));
    }
}
