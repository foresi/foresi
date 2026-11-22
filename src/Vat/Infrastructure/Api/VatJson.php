<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api;

use Foresi\Shared\Infrastructure\Api\JsonValues;
use Foresi\Vat\Application\PeriodList;
use Foresi\Vat\Application\PeriodView;
use Foresi\Vat\Application\VatSettingsView;

/**
 * The VAT module as REST and MCP write it: snake_case keys, money in minor
 * units with its currency, a day as YYYY-MM-DD. Every answer about periods
 * says it is an estimate.
 */
final class VatJson
{
    /**
     * @return array<string, mixed>
     */
    public static function periods(PeriodList $list): array
    {
        return [
            'estimate' => true,
            'reason' => $list->reason,
            'country' => $list->country,
            'from' => $list->from,
            'to' => $list->to,
            'periods' => array_map(self::period(...), $list->periods),
            'warnings' => $list->warnings,
            'unverified' => $list->unverified,
            'known_gaps' => $list->knownGaps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function period(PeriodView $view): array
    {
        return [
            'key' => $view->period->key,
            'length' => $view->period->length->value,
            'start' => JsonValues::date($view->period->start),
            'end' => JsonValues::date($view->period->end),
            'due_on' => JsonValues::date($view->dueOn),
            'refund_expected_on' => JsonValues::date($view->refundOn),
            'output' => JsonValues::money($view->output),
            'input' => JsonValues::money($view->input),
            'result' => JsonValues::money($view->result),
            'warnings' => $view->warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(VatSettingsView $view): array
    {
        return [
            'office_account' => $view->officeAccount,
            'variable_symbol' => $view->variableSymbol,
            'suggestion' => $view->suggestion instanceof \Foresi\Vat\Contract\PaymentSuggestion ? [
                'account_hint' => $view->suggestion->accountHint,
                'variable_symbol' => $view->suggestion->variableSymbol,
                'source_url' => $view->suggestion->sourceUrl,
            ] : null,
        ];
    }
}
