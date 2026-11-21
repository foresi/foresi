<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Api;

use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\Api\JsonValues;
use Foresi\TaxesAndContributions\Contract\AccrualMonth;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountLine;
use Foresi\TaxesAndContributions\Contract\AmountSum;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\CreditLine;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\FlatRateMonth;
use Foresi\TaxesAndContributions\Contract\FlatRateResult;
use Foresi\TaxesAndContributions\Contract\ForecastCertainty;
use Foresi\TaxesAndContributions\Contract\ForecastView;
use Foresi\TaxesAndContributions\Contract\MethodResult;
use Foresi\TaxesAndContributions\Contract\MonthStatus;
use Foresi\TaxesAndContributions\Contract\PossibleDuplicate;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\RuleRef;
use Foresi\TaxesAndContributions\Contract\SpouseInput;
use Foresi\TaxesAndContributions\Contract\StatusPeriod;
use Foresi\TaxesAndContributions\Contract\Step;
use Foresi\TaxesAndContributions\Contract\SwitchAdjustment;
use Foresi\TaxesAndContributions\Contract\YearInputsView;

/**
 * The owner profile, the actual amounts and the estimate as the API and MCP write them, in snake case, with amounts
 * as {amount_minor, currency} and days as 2026-11-12.
 */
final class TaxesJson
{
    /**
     * @return array<string, mixed>
     */
    public static function profile(ProfileView $profile): array
    {
        return [
            'statuses' => array_map(self::status(...), $profile->statuses),
            'years' => array_values(array_map(self::year(...), $profile->years)),
            'authorities' => array_map(self::authority(...), $profile->authorities),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(StatusPeriod $status): array
    {
        return [
            'id' => $status->id,
            'kind' => $status->kind->value,
            'value' => $status->value,
            'valid_from' => $status->validFrom->format('Y-m-d'),
            'base' => self::money($status->base),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function year(YearInputsView $year): array
    {
        $deductions = [];
        foreach ($year->deductions as $type => $amount) {
            $deductions[] = ['type' => $type, 'amount' => JsonValues::money($amount)];
        }

        return [
            'year' => $year->year,
            'expense_methods' => array_map(static fn (ExpenseMethod $method): string => $method->value, $year->expenseMethods),
            'chosen_method' => $year->chosenMethod?->value,
            'children' => array_map(static fn (ChildInput $child): array => [
                'label' => $child->label,
                'birth_date' => $child->birthDate->format('Y-m-d'),
                'order' => $child->order,
                'ztp_p' => $child->ztpP,
                'months' => $child->months,
            ], $year->children),
            'spouse' => $year->spouse instanceof SpouseInput ? ['months' => $year->spouse->months, 'ztp_p' => $year->spouse->ztpP, 'low_income' => $year->spouse->lowIncome] : null,
            'deductions' => $deductions,
            'other_bases' => [
                'employment' => self::money($year->employmentBase),
                'employment_tax_withheld' => self::money($year->employmentTaxWithheld),
                'rental' => self::money($year->rentalBase),
                'other' => self::money($year->otherBase),
            ],
            'last_known_tax' => self::money($year->lastKnownTax),
            'previous_year' => [
                'social_base' => self::money($year->previousSocialBase),
                'health_base' => self::money($year->previousHealthBase),
                'overview_filed_on' => $year->previousOverviewFiledOn?->format('Y-m-d'),
            ],
            'return_route' => $year->returnRoute->value,
            'activity_start' => $year->activityStart?->format('Y-m-d'),
            'average_wage_override' => self::money($year->averageWageOverride),
        ];
    }

    /**
     * @return array{authority: string, account: string, variable_symbol: string}
     */
    public static function authority(AuthorityAccountView $account): array
    {
        return ['authority' => $account->authority, 'account' => $account->account, 'variable_symbol' => $account->variableSymbol];
    }

    /**
     * @return array<string, mixed>
     */
    public static function amounts(AmountsView $amounts): array
    {
        $totals = [];
        foreach (AmountKind::cases() as $kind) {
            $totals[$kind->value] = self::totals($amounts->totalOf($kind));
        }

        return [
            'from' => $amounts->from->format('Y-m-d'),
            'to' => $amounts->to->format('Y-m-d'),
            'totals' => $totals,
            'months' => array_map(static fn (AmountSum $sum): array => [
                'month' => $sum->month,
                'kind' => $sum->kind->value,
                'source' => $sum->source,
                'gross' => JsonValues::money($sum->gross),
                'net' => JsonValues::money($sum->net),
                'vat' => JsonValues::money($sum->vat),
                'counted' => JsonValues::money($sum->counted),
            ], $amounts->sums),
            'amounts' => array_map(static fn (AmountLine $line): array => [
                'source' => $line->amount->source,
                'subject' => ['type' => $line->amount->subject->type, 'id' => $line->amount->subject->id, 'url' => $line->amount->subject->url],
                'kind' => $line->amount->kind->value,
                'paid_on' => $line->amount->paidOn->format('Y-m-d'),
                'tax_point_on' => $line->amount->taxPointOn?->format('Y-m-d'),
                'advance' => $line->amount->advance,
                'gross' => JsonValues::money($line->amount->gross),
                'net' => JsonValues::money($line->amount->net),
                'vat' => JsonValues::money($line->amount->vat),
                'gross_czk' => JsonValues::money($line->grossCzk),
                'net_czk' => JsonValues::money($line->netCzk),
                'vat_czk' => JsonValues::money($line->vatCzk),
                'counted_czk' => JsonValues::money($line->countedCzk),
            ], $amounts->lines),
            'unavailable_sources' => $amounts->unavailableSources,
            'restricted_sources' => $amounts->restrictedSources,
            'missing_rates' => $amounts->missingRates,
            'unverified' => $amounts->unverified,
            'known_gaps' => $amounts->knownGaps,
        ];
    }

    /**
     * The estimate: "to date" and "year end" apart, each null with its reason
     * until it can be computed.
     *
     * @return array<string, mixed>
     */
    public static function estimate(EstimateView $estimate): array
    {
        $amounts = $estimate->amounts;
        $sources = null;
        if ($amounts instanceof AmountsView) {
            $totals = [];
            foreach (AmountKind::cases() as $kind) {
                $totals[$kind->value] = self::totals($amounts->totalOf($kind));
            }
            $sources = [
                'from' => $amounts->from->format('Y-m-d'),
                'to' => $amounts->to->format('Y-m-d'),
                'totals' => $totals,
                'unavailable_sources' => $amounts->unavailableSources,
                'restricted_sources' => $amounts->restrictedSources,
                'missing_rates' => $amounts->missingRates,
            ];
        }

        return [
            'estimate' => true,
            'year' => $estimate->year,
            'today' => $estimate->today->format('Y-m-d'),
            'to_date' => $estimate->toDate instanceof RegimeResult ? self::result($estimate->toDate) : null,
            'to_date_reason' => $estimate->toDateReason,
            'year_end' => $estimate->yearEnd instanceof RegimeResult ? self::result($estimate->yearEnd) : null,
            'year_end_reason' => $estimate->yearEndReason,
            'forecast' => $estimate->forecast instanceof ForecastView ? self::forecast($estimate->forecast) : null,
            'forecast_by_certainty' => $estimate->forecast instanceof ForecastView ? self::byCertainty($estimate->forecast) : null,
            'accruals' => array_map(static fn (string $method, array $months): array => [
                'method' => $method,
                'months' => array_map(static fn (AccrualMonth $month): array => [
                    'month' => $month->month,
                    'profit' => JsonValues::money($month->profit),
                    'minimum' => JsonValues::money($month->minimum),
                    'levies' => JsonValues::money($month->levies),
                ], $months),
            ], array_keys($estimate->accruals), array_values($estimate->accruals)),
            'amounts' => $sources,
            'payments' => array_map(static fn (DuePayment $payment): array => [
                'key' => $payment->key(),
                'authority' => $payment->authority->value,
                'kind' => $payment->kind,
                'period' => $payment->period,
                'direction' => $payment->comesIn() ? 'in' : 'out',
                'payable_from' => $payment->payableFrom->format('Y-m-d'),
                'due_on' => $payment->dueOn->format('Y-m-d'),
                'amount' => JsonValues::money($payment->amount),
                'state' => $payment->state,
                'match_rule' => $payment->hasMatchRule() ? ['account' => $payment->account, 'variable_symbol' => $payment->variableSymbol] : null,
                'paid_by' => null === $payment->paidBy ? null : ['bank_movement_id' => $payment->paidBy, 'booked_on' => $payment->paidOn?->format('Y-m-d')],
            ], $estimate->payments),
            'possible_duplicates' => array_map(static fn (PossibleDuplicate $item): array => [
                'planned_item_id' => $item->id,
                'name' => $item->name,
                'category' => $item->category,
            ], $estimate->possibleDuplicates),
            'warnings' => $estimate->warnings,
            'unverified' => $estimate->unverified,
            'known_gaps' => $estimate->knownGaps,
        ];
    }

    /**
     * The forecast part of the year end, from today to 31 December.
     *
     * @return array<string, mixed>
     */
    private static function forecast(ForecastView $forecast): array
    {
        $totals = [];
        foreach (AmountKind::cases() as $kind) {
            $totals[$kind->value] = self::totals($forecast->totalOf($kind));
        }

        return [
            'from' => $forecast->from->format('Y-m-d'),
            'to' => $forecast->to->format('Y-m-d'),
            'totals' => $totals,
            'flags' => $forecast->flags,
            'incomplete' => $forecast->incomplete,
            'unavailable_sources' => $forecast->unavailableSources,
            'restricted_sources' => $forecast->restrictedSources,
        ];
    }

    /**
     * @return array<string, array<string, array<string, array{amount_minor: int, currency: string}>>>
     */
    private static function byCertainty(ForecastView $forecast): array
    {
        $split = [];
        foreach (ForecastCertainty::cases() as $certainty) {
            foreach (AmountKind::cases() as $kind) {
                $split[$certainty->value][$kind->value] = self::totals($forecast->totalOfCertainty($certainty, $kind));
            }
        }

        return $split;
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(RegimeResult $result): array
    {
        return [
            'regime' => $result->regime,
            'rules' => [
                'year' => $result->ruleYear,
                'status' => $result->rules->value,
                'values' => array_map(static fn (RuleRef $rule): array => [
                    'key' => $rule->key,
                    'value' => $rule->value,
                    'valid_from' => $rule->validFrom->format('Y-m-d'),
                    'source_url' => $rule->sourceUrl,
                    'unverified' => $rule->unverified,
                ], $result->rulesUsed),
            ],
            'methods' => array_map(static fn (MethodResult $method): array => [
                'method' => $method->method->value,
                'totals' => [
                    'income' => JsonValues::money($method->totals->income),
                    'costs' => JsonValues::money($method->totals->costs),
                    'expenses' => JsonValues::money($method->totals->expenses),
                    'profit' => JsonValues::money($method->totals->profit),
                    'social' => JsonValues::money($method->totals->social),
                    'health' => JsonValues::money($method->totals->health),
                    'sickness' => JsonValues::money($method->totals->sickness),
                    'income_tax' => JsonValues::money($method->totals->incomeTax),
                    'child_bonus' => JsonValues::money($method->totals->childBonus),
                    'levies' => JsonValues::money($method->totals->levies),
                    'take_home' => JsonValues::money($method->totals->takeHome),
                    'social_base' => JsonValues::money($method->totals->socialBase),
                    'flat_rate' => JsonValues::money($method->totals->flatRate),
                ],
                'steps' => array_map(static fn (Step $step): array => [
                    'key' => $step->key,
                    'input' => $step->input,
                    'rule' => $step->rule,
                    'source_url' => $step->sourceUrl,
                    'result' => $step->result,
                ], $method->steps),
            ], $result->methods),
            'cheaper_method' => $result->cheaperMethod?->value,
            'break_even' => self::money($result->breakEven),
            'credits' => array_map(static fn (CreditLine $credit): array => [
                'key' => $credit->key,
                'claimed' => $credit->claimed,
                'amount' => JsonValues::money($credit->amount),
                'explanation' => $credit->explanation,
            ], $result->credits),
            'months' => array_map(static fn (MonthStatus $month): array => [
                'month' => $month->month,
                'activity' => $month->activity,
                'health_minimum_exempt' => $month->healthMinimumExempt,
                'sickness_insurance' => $month->sicknessInsurance,
                'minimum_social_advance' => JsonValues::money($month->minimumSocialAdvance),
                'minimum_health_advance' => JsonValues::money($month->minimumHealthAdvance),
                'flat_rate_band' => $month->flatRateBand,
            ], $result->months),
            'flat_rate' => $result->flatRate instanceof FlatRateResult ? self::flatRate($result->flatRate) : null,
            'switch_adjustment' => $result->switchAdjustment instanceof SwitchAdjustment ? self::switchAdjustment($result->switchAdjustment) : null,
        ];
    }

    /**
     * The adjustment of the year before for a change of the expense method,
     * apart from the totals.
     *
     * @return array<string, mixed>
     */
    private static function switchAdjustment(SwitchAdjustment $switch): array
    {
        return [
            'adjusted_year' => $switch->adjustedYear,
            'from_method' => $switch->from->value,
            'into' => $switch->into,
            'open_on' => $switch->openOn->format('Y-m-d'),
            'receivables' => JsonValues::money($switch->receivables),
            'payables' => JsonValues::money($switch->payables),
            'base_change' => JsonValues::money($switch->baseChange),
            'previous_tax_base' => self::money($switch->previousTaxBase),
            'rate' => $switch->rate,
            'tax' => JsonValues::money($switch->tax),
            'due_on' => $switch->dueOn->format('Y-m-d'),
            'source_url' => $switch->sourceUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function flatRate(FlatRateResult $flatRate): array
    {
        return [
            'months' => array_map(static fn (FlatRateMonth $month): array => [
                'month' => $month->month,
                'band' => $month->band,
                'income_tax' => JsonValues::money($month->incomeTax),
                'social' => JsonValues::money($month->social),
                'health' => JsonValues::money($month->health),
                'total' => JsonValues::money($month->total()),
            ], $flatRate->months),
            'income_tax' => JsonValues::money($flatRate->incomeTax),
            'social' => JsonValues::money($flatRate->social),
            'health' => JsonValues::money($flatRate->health),
            'total' => JsonValues::money($flatRate->total),
        ];
    }

    /**
     * @return array<string, array{amount_minor: int, currency: string}>
     */
    private static function totals(AmountTotals $totals): array
    {
        return [
            'gross' => JsonValues::money($totals->gross),
            'net' => JsonValues::money($totals->net),
            'vat' => JsonValues::money($totals->vat),
            'counted' => JsonValues::money($totals->counted),
        ];
    }

    /**
     * @return array{amount_minor: int, currency: string}|null
     */
    private static function money(?Money $amount): ?array
    {
        return $amount instanceof Money ? JsonValues::money($amount) : null;
    }
}
