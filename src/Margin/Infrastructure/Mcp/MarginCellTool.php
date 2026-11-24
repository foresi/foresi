<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Mcp;

use Foresi\Margin\Application\MarginCell;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * What stands behind one cell of the margin table, as GET /api/v1/margin/cell writes it.
 */
final readonly class MarginCellTool implements ReadTool
{
    public function __construct(private MarginCell $cell)
    {
    }

    public function name(): string
    {
        return 'foresi_client_margin_cell';
    }

    public function title(): string
    {
        return 'Client margin, one cell';
    }

    public function scope(): string
    {
        return 'margin:read';
    }

    public function description(): string
    {
        return 'Returns the documents behind one cell of foresi_client_margin: the revenue_items (issued documents) and the cost_items (cost shares), each with document_id, label, text, amount in CZK, source (revenue, or line, document, planned_item, supplier, unassigned for a cost), kind (the kind of a revenue document) and url, '
            .'with the cell\'s revenue, costs and margin. row is client:<contact id>, own or unassigned as the table gives it; month is YYYY-MM.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'row' => ['type' => 'string', 'description' => 'client:<contact id>, own or unassigned, as foresi_client_margin gives it.'],
                'month' => ['type' => 'string', 'description' => 'The month, YYYY-MM.'],
            ],
            'required' => ['row', 'month'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $view = $this->cell->of(ToolArguments::text($arguments, 'row') ?? '', ToolArguments::text($arguments, 'month') ?? '');
        } catch (MarginRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(MarginJson::cellDetail($view), \sprintf('%d revenue items and %d cost items in the cell.', \count($view->revenue), \count($view->costs)));
    }
}
