<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Mcp;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\StaffOnlyTool;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;
use Foresi\TaxesAndContributions\Application\Estimates;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Infrastructure\Api\TaxesJson;

/**
 * The estimate of the taxes and contributions, as GET
 * /api/v1/taxes-and-contributions/estimate writes it.
 */
final readonly class TaxEstimateTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private Estimates $estimates, private InstanceClock $clock)
    {
    }

    public function name(): string
    {
        return 'foresi_tax_estimate';
    }

    public function title(): string
    {
        return 'Estimate the taxes and contributions of a year';
    }

    public function scope(): string
    {
        return 'taxes:read';
    }

    public function description(): string
    {
        return 'Estimates what the owner earns, what goes to taxes and contributions and what they keep, for a year. It is an estimate, not a basis for the tax return. '
            .'{to_date} is computed from the income received and the costs paid so far and the months of activity up to today. '
            .'{year_end} adds the Cash flow forecast from today to 31 December and counts every month; it is never mixed with {to_date}. It leaves out the forecast of the taxes and contributions themselves and the categories taxes, insurance, vat, loans and owner_draws. '
            .'{forecast} holds the forecast part: its {totals} (income and cost, each gross, net, vat and counted), its {flags} (forecast.plan_no_vat_split: a recurring invoice or planned item counts as stated, with no VAT split; forecast.no_vat_split_restricted or forecast.no_vat_split_unknown: a document counts gross), {incomplete} and {unavailable_sources}. {forecast_by_certainty} splits it into document, schedule and plan. '
            .'{accruals} spreads the levies of the year end over its months by each month\'s share of the profit, with the smallest advances in a month of low profit. '
            .'Without Cash flow {year_end} is null with {year_end_reason} cash_flow_not_installed; a past year\'s {year_end} is what was paid. '
            .'Each of {to_date.methods} is one expense method (actual costs or a lump sum) with its {totals} (income, costs, profit, social, health, sickness, income_tax, child_bonus, levies, take_home) and its {steps}, each with its input, rule, source_url and result, so any number can be explained. '
            .'{cheaper_method} and {break_even} compare the methods, {credits} lists each tax credit with what it needs, {months} the activity of each month and its smallest advances, and {rules} the rule year, its status (published, derived, latest_used) and every value used with its source. '
            .'{warnings}, {unverified} and {known_gaps} say what an accountant should confirm or what the estimate leaves out. Only the owner of the organisation may read it.';
    }

    /**
     * The owner's own figures, never a guest's (ADR 0041).
     */
    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'year' => ['type' => 'integer', 'minimum' => 2000, 'description' => 'The year; this one when left out.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $year = $arguments['year'] ?? null;
        if (null !== $year && !\is_int($year)) {
            throw ToolRefusal::invalidArgument('The argument "year" must be an integer.');
        }
        try {
            $estimate = $this->estimates->of($year ?? (int) $this->clock->today()->format('Y'));
        } catch (TaxesRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $summary = \sprintf('No estimate to date for %d: %s.', $estimate->year, $estimate->toDateReason ?? 'unknown');
        if ($estimate->toDate instanceof RegimeResult) {
            $parts = [];
            foreach ($estimate->toDate->methods as $method) {
                $parts[] = \sprintf('%s: levies %s, take-home %s', $method->method->value, $method->totals->levies->format('en'), $method->totals->takeHome->format('en'));
            }
            $summary = \sprintf('Estimate to date for %d (rules %d, %s): %s.', $estimate->year, $estimate->toDate->ruleYear, $estimate->toDate->rules->value, implode('; ', $parts));
        }
        if ($estimate->yearEnd instanceof RegimeResult) {
            $parts = [];
            foreach ($estimate->yearEnd->methods as $method) {
                $parts[] = \sprintf('%s: levies %s, take-home %s', $method->method->value, $method->totals->levies->format('en'), $method->totals->takeHome->format('en'));
            }
            $summary .= \sprintf(' Year end with the forecast: %s.', implode('; ', $parts));
        } elseif (null !== $estimate->yearEndReason) {
            $summary .= \sprintf(' No year end: %s.', $estimate->yearEndReason);
        }

        return new ToolOutput(TaxesJson::estimate($estimate), $summary);
    }
}
