<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Mcp;

use Foresi\Margin\Application\Unrebilled;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * The costs paid and not rebilled, as GET /api/v1/margin/unrebilled writes them.
 */
final readonly class UnrebilledTool implements ReadTool
{
    public function __construct(private Unrebilled $unrebilled)
    {
    }

    public function name(): string
    {
        return 'foresi_list_unrebilled_costs';
    }

    public function title(): string
    {
        return 'Costs paid and not rebilled';
    }

    public function scope(): string
    {
        return 'margin:read';
    }

    public function description(): string
    {
        return 'Lists the occurrences of planned costs that were paid and not rebilled: a cost document is paired with the occurrence, the recurring invoice of a client has a line that rebills the planned cost (foresi_set_rebill_link), the client bears a share of the cost that month, and no invoice line of the client is paired with the occurrence (foresi_pair_rebill_line). '
            .'Each entry has the planned item (id, label, url), occurrence_on, its month, the recipient_id (the client) and the template line to look at (template_id, template_url, recurring_line_id). Earliest first. A month before the link was made is never listed. A proposed pairing does not count as paid. '
            .'from and to are months written YYYY-MM, at most 24 months apart; the last three months ending with the current one when left out.';
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
            $found = $this->unrebilled->between(ToolSchema::text($arguments, 'from'), ToolSchema::text($arguments, 'to'));
        } catch (MarginRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(MarginJson::unrebilled($found), \sprintf('%d costs paid and not rebilled.', \count($found)));
    }
}
