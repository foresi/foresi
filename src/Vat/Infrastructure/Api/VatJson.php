<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api;

use Foresi\Shared\Infrastructure\Api\JsonValues;
use Foresi\Vat\Application\CalculationStep;
use Foresi\Vat\Application\PeriodDetail;
use Foresi\Vat\Application\PeriodList;
use Foresi\Vat\Application\PeriodView;
use Foresi\Vat\Application\VatPart;
use Foresi\Vat\Application\VatSettingsView;
use Foresi\Vat\Contract\DocumentVat;

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
            'unavailable_sources' => $list->unavailableSources,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(PeriodDetail $detail): array
    {
        return self::periods($detail->list) + [
            'period' => self::period($detail->period),
            'documents' => array_map(self::document(...), $detail->documents),
            'restricted_sources' => $detail->restrictedSources,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(DocumentVat $document): array
    {
        return [
            'source' => $document->source,
            'subject' => ['type' => $document->subject->type, 'id' => $document->subject->id, 'url' => $document->subject->url],
            'label' => $document->label,
            'counts_on' => JsonValues::date($document->countsOn),
            'tax_point_on' => JsonValues::date($document->taxPointOn),
            'output' => JsonValues::money($document->output),
            'input' => JsonValues::money($document->input),
            'missing_czk' => $document->missingCzk,
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
            'breakdown' => [
                'documents' => self::part($view->amounts->documentsPart),
                'schedules' => self::part($view->amounts->schedulesPart),
                'plans' => self::part($view->amounts->plansPart),
            ],
            'steps' => array_map(static fn (CalculationStep $step): array => [
                'name' => $step->name,
                'amount' => JsonValues::money($step->amount),
                'rule' => $step->rule,
                'sources' => $step->sources,
            ], $view->amounts->steps),
            'warnings' => $view->warnings,
            'missing_czk' => $view->amounts->missingCzk,
        ];
    }

    /**
     * @return array{output: array<string, mixed>, input: array<string, mixed>}
     */
    private static function part(VatPart $part): array
    {
        return ['output' => JsonValues::money($part->output), 'input' => JsonValues::money($part->input)];
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
