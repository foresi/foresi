<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Mcp;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Infrastructure\Api\CashFlowJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * The cash-flow forecast of the firm, as GET /api/v1/cash-flow/forecast writes it.
 */
final readonly class CashFlowForecastTool implements ReadTool
{
    public function __construct(private Forecasts $forecasts, private CashFlowAccess $access)
    {
    }

    public function name(): string
    {
        return 'foresi_cash_flow_forecast';
    }

    public function title(): string
    {
        return 'Cash-flow forecast';
    }

    public function scope(): string
    {
        return 'cashflow:read';
    }

    public function description(): string
    {
        return 'Returns how much money the firm will have, in CZK at today\'s ČNB rate: by ISO weeks until the end of next month, then by calendar months, 12 months in all. '
            .'It starts from the latest balance of each bank account (balance, with stale when it is older than 7 days) and adds every expected flow: planned items and whatever the installed modules expect, such as open invoices. '
            .'Each period has opening, inflows and outflows by category, closing and its items; an item keeps its own amount and currency, and one still open before today is counted in the first period with the flag overdue, or with the flag awaited and not overdue when it is past its date but not late, such as a tax estimate. '
            .'It also answers the lowest balance and its day, the first day below the minimum balance (shortfall_on), and incomplete with the sources or rates that failed. '
            .'certainty keeps only the flows of the certainties listed (document, schedule, plan, estimate); the balances, lowest and shortfall_on then follow those flows, and inflows and outflows hold by_certainty too. '
            .'The ids of the bank accounts for foresi_record_balance_checkpoint are in balance.accounts.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ToolArguments::dateSchema('The first day, today or later; today when left out.'),
                'months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Forecasts::MAX_MONTHS, 'description' => 'How many calendar months the forecast covers; 12 when left out.'],
                'weeks_until' => ToolArguments::dateSchema('Weeks are used up to the week that holds this day, then months; the end of next month when left out.'),
                'certainty' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => FlowCertainty::values()], 'uniqueItems' => true, 'description' => 'The certainties counted; all when left out. The balance today is never filtered.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $months = $arguments['months'] ?? null;
        if (null !== $months && !\is_int($months)) {
            throw ToolRefusal::invalidArgument('The argument "months" must be an integer.');
        }
        $certainty = $arguments['certainty'] ?? null;
        if (null !== $certainty && !\is_array($certainty)) {
            throw ToolRefusal::invalidArgument('The argument "certainty" must be a list.');
        }
        try {
            $forecast = $this->forecasts->of(ToolArguments::text($arguments, 'from'), $months, ToolArguments::text($arguments, 'weeks_until'), null === $certainty ? null : Forecasts::certainties(array_values($certainty)));
        } catch (CashFlowRefused $refused) {
            throw CashFlowToolRefusal::of($refused);
        }
        $count = \count($forecast->periods);

        return new ToolOutput(CashFlowJson::forecast($forecast, $this->access->firm()), \sprintf('The forecast in %d periods, %s.', $count, $forecast->isIncomplete() ? 'incomplete' : 'complete'));
    }
}
