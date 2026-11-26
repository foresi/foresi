<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatRefused;
use Foresi\Vat\Application\VatSettingsReads;
use Foresi\Vat\Infrastructure\Api\VatJson;

/**
 * The VAT periods of the firm with its payment details, as GET
 * /api/v1/vat/periods and GET /api/v1/vat/settings write them.
 */
final readonly class VatPeriodsTool implements ReadTool
{
    public function __construct(private VatPeriods $periods, private VatSettingsReads $settings)
    {
    }

    public function name(): string
    {
        return 'foresi_vat_periods';
    }

    public function title(): string
    {
        return 'VAT periods';
    }

    public function scope(): string
    {
        return 'vat:read';
    }

    public function description(): string
    {
        return 'Returns the VAT periods of the firm, always an estimate (estimate: true): from the day it became a VAT payer, each calendar month or quarter clipped to the days it is a payer, with its due date (due_on), the day a refund is expected (refund_expected_on), the output VAT, the input VAT and the result in CZK. '
            .'Nothing is computed before a stated payer date: reason is then payer_date_missing, or not_a_payer, or country_not_supported. '
            .'warnings lists what the country does not allow in the VAT statuses, such as quarter_not_allowed; unverified lists the rules nobody has confirmed yet, and known_gaps what the amounts do not count yet. '
            .'Each period has expected (the return amount a person entered with foresi_set_vat_return_amount, else the result), state (estimated, return_entered, settled once a bank payment pays it or a person marked it, not_yet_paid once its due date or refund date passed; never overdue), payments, paid and difference (paid minus expected, information only: a smaller payment still settles the period). '
            .'The bank movements to or from the tax office are matched by the account and the variable symbol, never by the amount; unassigned_payments lists those that pay no period, for foresi_assign_vat_payment. possible_duplicates lists the planned items of the category vat that may count the VAT twice. '
            .'settings holds where the firm pays its VAT, null until foresi_set_vat_settings sets it, and the country\'s suggestion.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'The first month, such as "2026-11", or a quarter, such as "2026-Q4"; the first period when left out.'],
                'to' => ['type' => 'string', 'description' => 'The last month or quarter; 12 months after this one when left out, at most 24.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $list = $this->periods->list(ToolSchema::text($arguments, 'from'), ToolSchema::text($arguments, 'to'));
            $settings = $this->settings->current();
        } catch (VatRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(VatJson::periods($list) + ['settings' => VatJson::settings($settings)], \sprintf('%d VAT periods, an estimate%s.', \count($list->periods), null === $list->reason ? '' : ' ('.$list->reason.')'));
    }
}
