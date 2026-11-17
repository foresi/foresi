<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Api;

use App\CashFlow\Application\AccountOpening;
use App\CashFlow\Application\Forecast;
use App\CashFlow\Application\ForecastLine;
use App\CashFlow\Application\HiddenTotal;
use App\CashFlow\Application\PeriodForecast;
use App\CashFlow\Contract\FlowFlag;
use App\CashFlow\Domain\Checkpoint;
use App\CashFlow\Domain\ExpectedDate;
use App\CashFlow\Domain\PlannedItem;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Api\JsonValues;

/**
 * The cash flow as the REST API and MCP write it (snake case, money in minor
 * units with its currency, days in ISO 8601).
 */
final class CashFlowJson
{
    /**
     * @return array<string, mixed>
     */
    public static function plannedItem(PlannedItem $item, \DateTimeZone $zone): array
    {
        $recurrence = $item->recurrence($zone);
        $match = $item->matchRule();
        $tolerance = $match->toleranceMinor();
        $counterparty = $item->counterparty();

        return [
            'id' => $item->id(),
            'firm' => ['id' => $item->firm()->id(), 'name' => $item->firm()->name()],
            'direction' => $item->direction()->value,
            'name' => $item->name(),
            'category' => $item->category()->value,
            'amount' => JsonValues::money($item->amount()),
            'counterparty' => $counterparty instanceof \App\Party\Domain\Party ? ['id' => $counterparty->id(), 'name' => $counterparty->displayName()] : null,
            'on' => JsonValues::date($item->firstOn()),
            'repeat' => $recurrence?->unit()->value,
            'every' => $recurrence?->every(),
            'last_day_of_month' => $recurrence?->onLastDayOfMonth() ?? false,
            'ends_on' => JsonValues::date($recurrence?->endOn()),
            'ends_after' => $recurrence?->endAfter(),
            'included' => $item->isIncluded(),
            'skipped_on' => $item->skippedOn(),
            'match' => [
                'account' => $match->counterpartyAccount(),
                'variable_symbol' => $match->variableSymbol(),
                'tolerance' => null === $tolerance ? null : JsonValues::money(Money::ofMinor($tolerance, $item->amount()->currency())),
            ],
            'created_at' => JsonValues::timestamp($item->createdAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function checkpoint(Checkpoint $checkpoint): array
    {
        $account = $checkpoint->account();

        return [
            'id' => $checkpoint->id(),
            'bank_account' => ['id' => $account->id(), 'iban' => $account->iban(), 'label' => $account->label()],
            'firm_id' => $account->firm()->id(),
            'on' => JsonValues::date($checkpoint->on()),
            'amount' => JsonValues::money($checkpoint->amount()),
            'recorded_at' => JsonValues::timestamp($checkpoint->recordedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function expectedDate(string $type, string $id, ?ExpectedDate $expected): array
    {
        return ['subject_type' => $type, 'subject_id' => $id, 'expected_on' => JsonValues::date($expected?->expectedOn())];
    }

    /**
     * @return array<string, mixed>
     */
    public static function minimumBalance(string $firmId, ?Money $amount): array
    {
        return ['firm_id' => $firmId, 'amount' => $amount instanceof Money ? JsonValues::money($amount) : null];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forecast(Forecast $forecast): array
    {
        $periods = $forecast->periods;

        return [
            'firm' => ['id' => $forecast->firm->id(), 'name' => $forecast->firm->name()],
            'currency' => Currency::CZK->value,
            'today' => JsonValues::date($forecast->today),
            'from' => JsonValues::date($forecast->from),
            'to' => JsonValues::date([] === $periods ? null : $periods[array_key_last($periods)]->period->end),
            'balance' => [
                'amount' => JsonValues::money($forecast->opening),
                'as_of' => JsonValues::date($forecast->balanceAsOf),
                'stale' => $forecast->stale,
                'accounts' => array_map(self::account(...), $forecast->accounts),
            ],
            'periods' => array_map(self::period(...), $periods),
            'lowest' => ['amount' => JsonValues::money($forecast->lowest), 'on' => JsonValues::date($forecast->lowestOn)],
            'minimum_balance' => $forecast->minimum instanceof Money ? JsonValues::money($forecast->minimum) : null,
            'shortfall_on' => JsonValues::date($forecast->shortfallOn),
            'incomplete' => $forecast->isIncomplete(),
            'unavailable_sources' => $forecast->unavailableSources,
            'unavailable_rates' => $forecast->unavailableRates,
            'restricted_sources' => $forecast->restrictedSources,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function account(AccountOpening $opening): array
    {
        return [
            'id' => $opening->account->id(),
            'iban' => $opening->account->iban(),
            'label' => $opening->account->label(),
            'balance' => $opening->balance instanceof Money ? JsonValues::money($opening->balance) : null,
            'amount' => $opening->amount instanceof Money ? JsonValues::money($opening->amount) : null,
            'as_of' => JsonValues::date($opening->asOf),
            'source' => $opening->source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function period(PeriodForecast $period): array
    {
        return [
            'key' => $period->period->key(),
            'kind' => $period->period->kind->value,
            'start' => JsonValues::date($period->period->start),
            'end' => JsonValues::date($period->period->end),
            'opening' => JsonValues::money($period->opening),
            'inflows' => self::totals($period->inflows),
            'outflows' => self::totals($period->outflows),
            'closing' => JsonValues::money($period->closing),
            'items' => array_map(self::line(...), $period->lines),
            'hidden_items' => array_map(static fn (HiddenTotal $hidden): array => [
                'source' => $hidden->source,
                'direction' => $hidden->direction->value,
                'category' => $hidden->category->value,
                'amount_czk' => JsonValues::money($hidden->amount),
                'count' => $hidden->count,
            ], $period->hidden),
        ];
    }

    /**
     * @param array<string, Money> $byCategory
     *
     * @return array<string, mixed>
     */
    private static function totals(array $byCategory): array
    {
        $total = Money::ofMinor(0, Currency::CZK);
        foreach ($byCategory as $amount) {
            $total = $total->plus($amount);
        }
        ksort($byCategory);

        // An empty object, not an empty list, when nothing flows.
        return ['total' => JsonValues::money($total), 'by_category' => [] === $byCategory ? new \stdClass() : array_map(JsonValues::money(...), $byCategory)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(ForecastLine $line): array
    {
        $flow = $line->flow;

        return [
            'source' => $flow->source,
            'subject' => ['type' => $flow->subject->type, 'id' => $flow->subject->id, 'url' => $flow->subject->url],
            'direction' => $flow->direction->value,
            'label' => $flow->label,
            'category' => $flow->category->value,
            'certainty' => $flow->certainty->value,
            'flags' => array_map(static fn (FlowFlag $flag): string => $flag->value, $flow->flags),
            'expected_on' => JsonValues::date($flow->expectedOn),
            'counted_on' => JsonValues::date($line->countedOn),
            'amount' => JsonValues::money($flow->amount),
            'amount_czk' => JsonValues::money($line->amount),
            'counterparty_id' => $flow->counterpartyId,
        ];
    }
}
