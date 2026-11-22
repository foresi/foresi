<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api;

use Foresi\Shared\Infrastructure\Api\JsonValues;
use Foresi\Vat\Application\CalculationStep;
use Foresi\Vat\Application\PaymentAssignmentView;
use Foresi\Vat\Application\PaymentView;
use Foresi\Vat\Application\PeriodDetail;
use Foresi\Vat\Application\PeriodList;
use Foresi\Vat\Application\PeriodView;
use Foresi\Vat\Application\ScheduleAmount;
use Foresi\Vat\Application\VatPart;
use Foresi\Vat\Application\VatSettingsView;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\PossibleDuplicate;

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
            'unassigned_payments' => array_map(self::payment(...), $list->unassigned),
            'possible_duplicates' => array_map(static fn (PossibleDuplicate $item): array => ['id' => $item->id, 'name' => $item->name, 'url' => $item->url], $list->duplicates),
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
            'schedules' => array_map(self::schedule(...), $detail->schedules),
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
    public static function schedule(ScheduleAmount $amount): array
    {
        $schedule = $amount->schedule;

        return [
            'source' => $schedule->source,
            'subject' => ['type' => $schedule->subject->type, 'id' => $schedule->subject->id, 'url' => $schedule->subject->url],
            'label' => $schedule->label,
            'tax_point_on' => JsonValues::date($schedule->taxPointOn),
            'output' => JsonValues::money($amount->output),
            'output_in_currency' => JsonValues::money($schedule->output),
            'fx_today' => $amount->fxToday,
            'missing_czk' => $amount->missingCzk,
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
            'return_amount' => $view->returnAmount instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($view->returnAmount) : null,
            'expected' => JsonValues::money($view->expected),
            'state' => $view->state->value,
            'paid' => $view->paid instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($view->paid) : null,
            'difference' => $view->difference instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($view->difference) : null,
            'payments' => array_map(self::payment(...), $view->payments),
            'settled_mark' => $view->mark instanceof \Foresi\Vat\Application\SettledMark ? ['at' => JsonValues::timestamp($view->mark->at), 'note' => $view->mark->note] : null,
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
     * @return array<string, mixed>
     */
    public static function payment(PaymentView $view): array
    {
        return [
            'movement_id' => $view->payment->id,
            'on' => JsonValues::date($view->payment->on),
            'amount' => JsonValues::money($view->payment->amount),
            'direction' => $view->payment->incoming ? 'in' : 'out',
            'rule' => $view->payment->rule,
            'period' => $view->period,
            'manual' => $view->manual,
            'unverified' => $view->unverified,
        ];
    }

    /**
     * @return array{movement_id: string, period: ?string, automatic: bool}
     */
    public static function assignment(PaymentAssignmentView $view): array
    {
        return ['movement_id' => $view->movementId, 'period' => $view->period, 'automatic' => $view->automatic];
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
