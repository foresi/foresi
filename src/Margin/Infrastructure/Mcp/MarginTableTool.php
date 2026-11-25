<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Mcp;

use Foresi\Margin\Application\MarginTable;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * The margin of the clients by month, as GET /api/v1/margin writes it.
 */
final readonly class MarginTableTool implements ReadTool
{
    public function __construct(private MarginTable $table)
    {
    }

    public function name(): string
    {
        return 'foresi_client_margin';
    }

    public function title(): string
    {
        return 'Client margin';
    }

    public function scope(): string
    {
        return 'margin:read';
    }

    public function description(): string
    {
        return 'Returns the margin of the firm\'s clients by month, in CZK: revenue minus costs. One row per client with revenue or costs in the range, the highest revenue first, then "own" (what the firm bears itself) and "unassigned" (costs nobody claims yet, never hidden). '
            .'Each row has row (client:<contact id>, own or unassigned), label, a cell for every month with revenue, costs, margin and unrebilled (how many paid costs of the client were not rebilled that month), and its total; the table has month_totals, a total and known_gaps (missing_exchange_rate with the ids of the documents that have no rate yet). '
            .'Revenue is the net of the issued invoices, payment tax documents and corrections by tax point; advances are left out. '
            .'from and to are months written YYYY-MM, at most 24 months apart; the last three months ending with the current one when left out. Open a cell with foresi_client_margin_cell.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'The first month, YYYY-MM; two months before the last one when left out.'],
                'to' => ['type' => 'string', 'description' => 'The last month, YYYY-MM; the current month when left out.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $view = $this->table->of(ToolSchema::text($arguments, 'from'), ToolSchema::text($arguments, 'to'));
        } catch (MarginRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(MarginJson::table($view), \sprintf('The margin of %d rows in %d months.', \count($view->rows), \count($view->months)));
    }
}
