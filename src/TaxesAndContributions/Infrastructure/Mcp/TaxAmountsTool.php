<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Infrastructure\Api\TaxesJson;

/**
 * The actual income and costs to date, as GET
 * /api/v1/taxes-and-contributions/amounts writes them.
 */
final readonly class TaxAmountsTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private Amounts $amounts)
    {
    }

    public function name(): string
    {
        return 'foresi_tax_amounts';
    }

    public function title(): string
    {
        return 'Get the actual income and costs for the taxes';
    }

    public function scope(): string
    {
        return 'taxes:read';
    }

    public function description(): string
    {
        return 'Returns the income received and the costs paid in a year to date, in CZK, on a cash basis: each payment counts on the day it was paid, a part payment splits into net and VAT in the ratio of its document, and a payment in another currency is converted at the ČNB rate of its day. '
            .'{totals} and {months} (by month, kind and source) hold gross, net, vat and counted: what the records count, the gross before the day the owner became a VAT payer and the net from it. {amounts} lists the payments of the documents the caller may read. '
            .'{unavailable_sources}, {restricted_sources}, {missing_rates}, {unverified} and {known_gaps} say what the figures leave out or what an accountant should confirm. Only the owner of the organisation may read it.';
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
                'year' => ['type' => 'integer', 'minimum' => 2000, 'description' => 'The year; this one, to today, when left out.'],
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
            $amounts = null === $year ? $this->amounts->thisYear() : $this->amounts->year($year);
        } catch (TaxesRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $income = $amounts->totalOf(AmountKind::Income)->counted;
        $costs = $amounts->totalOf(AmountKind::Cost)->counted;

        return new ToolOutput(TaxesJson::amounts($amounts), \sprintf('From %s to %s the records count %s of income and %s of costs.', $amounts->from->format('Y-m-d'), $amounts->to->format('Y-m-d'), $income->format('en'), $costs->format('en')));
    }
}
