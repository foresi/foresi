<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Mcp;

use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatRefused;
use Foresi\Vat\Infrastructure\Api\VatJson;

/**
 * One VAT period with the documents behind its amounts, as GET
 * /api/v1/vat/periods/{key} writes it.
 */
final readonly class VatPeriodTool implements ReadTool
{
    public function __construct(private VatPeriods $periods)
    {
    }

    public function name(): string
    {
        return 'foresi_get_vat_period';
    }

    public function title(): string
    {
        return 'VAT period';
    }

    public function scope(): string
    {
        return 'vat:read';
    }

    public function description(): string
    {
        return 'Returns one VAT period of the firm by its key, always an estimate (estimate: true): its output VAT, input VAT and result in CZK, the breakdown into documents, schedules and plans, the steps of the calculation, and the documents, the schedules and the plans counted. '
            .'An issued tax document counts on its tax point; a received document in its deduction month, and only when the firm was a VAT payer on its tax point (known_gaps: deduction_before_registration). A schedule is an occurrence of an active recurring invoice not issued yet: it adds output VAT on the tax point its invoice would get, in CZK at today\'s rate when the template is in another currency (fx_today); once issued it counts as a document only. A plan is an occurrence of a planned item with its VAT stated, from today on and not yet fulfilled by a bank movement: money planned in adds output VAT and money planned out input VAT, in the period of its day, when the firm is a payer on it. '
            .'restricted_sources lists the sources whose documents, schedules or plans the token may not read: their amounts count, the items are left out. unavailable_sources lists the sources not installed. warnings holds missing_czk_vat when a document or a schedule has no CZK amount of its VAT, which counts zero, and fx_today when a schedule is converted at today\'s rate. '
            .'The period also has expected, state, payments, paid, difference and settled_mark, as in foresi_vat_periods.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'The period, a month such as "2026-11" or a quarter such as "2026-Q4".'],
            ],
            'required' => ['key'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $detail = $this->periods->get(ToolArguments::text($arguments, 'key') ?? '');
        } catch (VatRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(VatJson::detail($detail), \sprintf('The VAT period %s with %d documents, an estimate.', $detail->period->period->key, \count($detail->documents)));
    }
}
