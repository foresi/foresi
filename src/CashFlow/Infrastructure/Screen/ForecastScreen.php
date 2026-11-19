<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Screen;

use Foresi\CashFlow\Application\AccountOpening;
use Foresi\CashFlow\Application\Forecast;
use Foresi\CashFlow\Application\ForecastLine;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Application\PeriodForecast;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Contract\PeriodKind;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Format\MoneyText;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the Cash flow page shows of a forecast: the four figures, what is
 * missing from it, and the table of its periods, as Agicap lays out the
 * categories as rows and the periods as columns. The page asks
 * Forecasts::of() directly (docs/cash-flow.md), so the screen and the API
 * read one forecast.
 */
final readonly class ForecastScreen
{
    /** The view of twelve calendar months: no week in front of them. */
    public const string MONTHS = 'months';

    public function __construct(
        private Forecasts $forecasts,
        private InstanceClock $clock,
        private MoneyText $money,
        private LocaleSwitcher $locales,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The forecast of one granularity. Weeks are used while a week starts on or
     * before weeks_until, so a day before today leaves none and the months start
     * today.
     *
     * @throws \Foresi\CashFlow\Application\CashFlowRefused
     */
    public function forecast(): Forecast
    {
        return $this->forecasts->of(null, 12, $this->clock->today()->modify('-1 day')->format('Y-m-d'));
    }

    /**
     * Whether there is anything to show: a balance or one expected amount.
     */
    public function isEmpty(Forecast $forecast): bool
    {
        if (array_any($forecast->accounts, static fn (AccountOpening $account): bool => $account->amount instanceof Money)) {
            return false;
        }

        return !array_any($forecast->periods, static fn (PeriodForecast $period): bool => [] !== $period->lines || [] !== $period->hidden);
    }

    /**
     * @return list<array<string, mixed>> the tiles of Ui:KpiStrip
     */
    public function kpis(Forecast $forecast): array
    {
        $last = $forecast->periods[array_key_last($forecast->periods)] ?? null;
        $minimum = $forecast->minimum instanceof Money
            ? ['label' => $this->trans('cash_flow.kpi.minimum'), 'value' => $this->whole($forecast->minimum), 'badge' => $forecast->shortfallOn instanceof \DateTimeImmutable
                ? ['label' => $this->trans('cash_flow.kpi.below_on', ['day' => $this->day($forecast->shortfallOn)]), 'tone' => 'red']
                : ['label' => $this->trans('cash_flow.kpi.never_below'), 'tone' => 'green']]
            : ['label' => $this->trans('cash_flow.kpi.minimum'), 'value' => $this->trans('cash_flow.kpi.minimum_unset'), 'note' => $this->trans('cash_flow.kpi.minimum_unset_note')];

        return [
            ['label' => $this->trans('cash_flow.kpi.today'), 'value' => $this->whole($forecast->opening), 'note' => $this->origin($forecast)],
            ['label' => $this->trans('cash_flow.kpi.lowest'), 'value' => $this->whole($forecast->lowest), 'note' => $this->trans('cash_flow.kpi.on_day', ['day' => $this->day($forecast->lowestOn)])],
            $minimum,
            ['label' => $this->trans('cash_flow.kpi.end'), 'value' => $this->whole($last instanceof PeriodForecast ? $last->closing : $forecast->opening), 'note' => $last instanceof PeriodForecast ? $this->trans('cash_flow.kpi.on_day', ['day' => $this->day($last->period->end)]) : null],
        ];
    }

    /**
     * What makes the forecast stale or incomplete, one sentence each, with the
     * account an Enter balance button should open with; null when nothing is missing.
     *
     * @return array{title: string, lines: list<string>, account: ?string}|null
     */
    public function alert(Forecast $forecast): ?array
    {
        if (!$forecast->stale && !$forecast->isIncomplete()) {
            return null;
        }
        $lines = [];
        $account = null;
        foreach ($forecast->accounts as $opening) {
            if (!$opening->amount instanceof Money) {
                $lines[] = $this->trans('cash_flow.alert.no_balance', ['account' => $this->accountName($opening->account)]);
                $account ??= $opening->account->id();
            }
        }
        if ([] === $forecast->accounts) {
            $lines[] = $this->trans('cash_flow.alert.no_account');
        } elseif ($forecast->stale && $forecast->balanceAsOf instanceof \DateTimeImmutable) {
            $lines[] = $this->trans('cash_flow.alert.stale', ['day' => $this->day($forecast->balanceAsOf)]);
        }
        foreach ($forecast->unavailableSources as $source) {
            $lines[] = $this->trans('cash_flow.alert.source', ['source' => $this->sourceName($source)]);
        }
        foreach ($forecast->unavailableRates as $currency) {
            $lines[] = $this->trans('cash_flow.alert.rate', ['currency' => $currency]);
        }

        return [
            'title' => $this->trans($forecast->isIncomplete() ? 'cash_flow.alert.incomplete_title' : 'cash_flow.alert.stale_title'),
            'lines' => $lines,
            'account' => $account,
        ];
    }

    /**
     * The periods as columns and opening, money in and out by category and the
     * closing as rows. A cell with money links to its items, which open in the sheet.
     *
     * @return array{columns: list<array{key: string, label: string, range: ?string}>, rows: list<array{kind: string, label: string, cells: list<array{text: string, href: ?string, below: bool}>}>}
     */
    public function table(Forecast $forecast): array
    {
        $columns = [];
        foreach ($forecast->periods as $period) {
            $columns[] = ['key' => $period->period->key(), 'label' => $this->periodLabel($period), 'range' => $this->partialRange($period)];
        }
        $rows = [['kind' => 'opening', 'label' => $this->trans('cash_flow.table.opening'), 'cells' => array_map(fn (PeriodForecast $period): array => $this->cell($period->opening), $forecast->periods)]];
        foreach ([FlowDirection::In, FlowDirection::Out] as $direction) {
            $rows[] = [
                'kind' => 'total',
                'label' => $this->trans(FlowDirection::In === $direction ? 'cash_flow.table.in' : 'cash_flow.table.out'),
                'cells' => array_map(fn (PeriodForecast $period): array => $this->flowCell($period, $direction, null), $forecast->periods),
            ];
            foreach (FlowCategory::cases() as $category) {
                if (!array_any($forecast->periods, static fn (PeriodForecast $period): bool => isset(self::flows($period, $direction)[$category->value]))) {
                    continue;
                }
                $rows[] = [
                    'kind' => 'category',
                    'label' => $this->trans('cash_flow.category.'.$category->value),
                    'cells' => array_map(fn (PeriodForecast $period): array => $this->flowCell($period, $direction, $category), $forecast->periods),
                ];
            }
        }
        $rows[] = ['kind' => 'closing', 'label' => $this->trans('cash_flow.table.closing'), 'cells' => array_map(
            fn (PeriodForecast $period): array => $this->cell($period->closing, below: $forecast->minimum instanceof Money && $period->closing->minor() < $forecast->minimum->minor()),
            $forecast->periods,
        )];

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * The period of a key the table gave, or null when the forecast has none.
     */
    public function period(Forecast $forecast, string $key): ?PeriodForecast
    {
        return array_find($forecast->periods, static fn (PeriodForecast $period): bool => $period->period->key() === $key);
    }

    /**
     * The items of one cell: what a period expects in one direction, of one category or all.
     *
     * @return array{title: string, total: string, items: list<array{label: string, source: string, day: string, flags: list<string>, amount: string, czk: ?string}>}
     */
    public function items(PeriodForecast $period, FlowDirection $direction, ?FlowCategory $category): array
    {
        $items = [];
        $total = Money::ofMinor(0, $period->opening->currency());
        foreach ($period->lines as $line) {
            if ($line->flow->direction !== $direction || ($category instanceof FlowCategory && $line->flow->category !== $category)) {
                continue;
            }
            $total = $total->plus($line->amount);
            $items[] = $this->item($line);
        }
        $what = $this->trans(FlowDirection::In === $direction ? 'cash_flow.table.in' : 'cash_flow.table.out');
        if ($category instanceof FlowCategory) {
            $what .= ' · '.$this->trans('cash_flow.category.'.$category->value);
        }

        return [
            'title' => $what.' · '.$this->periodLabel($period),
            'total' => $this->money->shown($total),
            'items' => $items,
        ];
    }

    /**
     * An account as the page names it: its label, or its IBAN in groups of four.
     */
    public function accountName(FirmBankAccount $account): string
    {
        return '' !== $account->label() ? $account->label() : trim(chunk_split($account->iban(), 4, ' '));
    }

    /**
     * A day as the page writes it: "3 Dec", "3. 12." in Czech.
     */
    public function day(\DateTimeImmutable $day): string
    {
        return $this->pattern('dMMM', $day);
    }

    /**
     * @return array{label: string, source: string, day: string, flags: list<string>, amount: string, czk: ?string}
     */
    private function item(ForecastLine $line): array
    {
        $flags = [];
        foreach ([FlowFlag::Overdue, FlowFlag::ExpectedDate, FlowFlag::NoDueDate, FlowFlag::Estimated] as $flag) {
            if ($line->flow->hasFlag($flag)) {
                $flags[] = $this->trans('cash_flow.flag.'.$flag->value);
            }
        }

        return [
            'label' => $line->flow->label,
            'source' => $this->subjectName($line->flow->subject->type, $line->flow->certainty->value),
            'day' => $this->day($line->countedOn),
            'flags' => $flags,
            'amount' => $this->money->shown($line->flow->amount),
            'czk' => $line->flow->amount->currency() === $line->amount->currency() ? null : $this->money->shown($line->amount),
        ];
    }

    /**
     * @return array{text: string, href: ?string, below: bool}
     */
    private function flowCell(PeriodForecast $period, FlowDirection $direction, ?FlowCategory $category): array
    {
        $flows = self::flows($period, $direction);
        $amount = null;
        foreach ($flows as $key => $sum) {
            if (!$category instanceof FlowCategory || $key === $category->value) {
                $amount = $amount instanceof Money ? $amount->plus($sum) : $sum;
            }
        }
        if (!$amount instanceof Money || $amount->isZero()) {
            return ['text' => '', 'href' => null, 'below' => false];
        }
        $readable = array_any($period->lines, static fn (ForecastLine $line): bool => $line->flow->direction === $direction && (!$category instanceof FlowCategory || $line->flow->category === $category));
        $href = $readable ? $this->urls->generate('app_cash_flow_items', array_filter([
            'period' => $period->period->key(),
            'direction' => $direction->value,
            'category' => $category?->value,
        ], static fn (?string $value): bool => null !== $value)) : null;

        return ['text' => $this->whole($amount), 'href' => $href, 'below' => false];
    }

    /**
     * @return array{text: string, href: ?string, below: bool}
     */
    private function cell(Money $amount, bool $below = false): array
    {
        return ['text' => $this->whole($amount), 'href' => null, 'below' => $below];
    }

    /**
     * @return array<string, Money>
     */
    private static function flows(PeriodForecast $period, FlowDirection $direction): array
    {
        return FlowDirection::In === $direction ? $period->inflows : $period->outflows;
    }

    /**
     * Where today's balance comes from: the day of the balance entered by hand,
     * and whether the bank movements booked since then are in it.
     */
    private function origin(Forecast $forecast): string
    {
        if (!$forecast->balanceAsOf instanceof \DateTimeImmutable) {
            return $this->trans('cash_flow.kpi.today_none');
        }
        $banked = array_any($forecast->accounts, static fn (AccountOpening $account): bool => null !== $account->source);

        return $this->trans($banked ? 'cash_flow.kpi.today_banked' : 'cash_flow.kpi.today_entered', ['day' => $this->day($forecast->balanceAsOf)]);
    }

    private function periodLabel(PeriodForecast $period): string
    {
        return PeriodKind::Week === $period->period->kind
            ? $this->day($period->period->start)
            : $this->pattern('MMMy', $period->period->start);
    }

    /**
     * The days of a period that is shorter than its week or month, such as the
     * first one, which starts today; null for a whole one.
     */
    private function partialRange(PeriodForecast $period): ?string
    {
        $start = $period->period->start;
        $end = $period->period->end;
        $whole = PeriodKind::Week === $period->period->kind
            ? '1' === $start->format('N') && '7' === $end->format('N')
            : '01' === $start->format('d') && $end->format('Y-m-d') === $start->modify('last day of this month')->format('Y-m-d');
        if ($whole) {
            return null;
        }

        return $this->trans('cash_flow.table.range', ['from' => $this->day($start), 'to' => $this->day($end)]);
    }

    private function subjectName(string $type, string $certainty): string
    {
        $key = 'cash_flow.subject.'.$type;
        $name = $this->translator->trans($key);

        return $name !== $key ? $name : $this->trans('cash_flow.certainty.'.$certainty);
    }

    private function sourceName(string $source): string
    {
        $key = 'cash_flow.source.'.str_replace(['.', '-'], '_', $source);
        $name = $this->translator->trans($key);

        return $name !== $key ? $name : $source;
    }

    /**
     * Whole crowns, as a forecast is read: "48 176 Kč".
     */
    private function whole(Money $money): string
    {
        $minor = $money->minor();
        $units = intdiv(abs($minor) + 50, 100) * ($minor < 0 ? -1 : 1);
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 0);

        return \sprintf("%s\u{a0}%s", $formatter->format($units, \NumberFormatter::TYPE_INT64), $this->money->unit($money->currency()));
    }

    private function pattern(string $skeleton, \DateTimeImmutable $day): string
    {
        $locale = $this->locales->getLocale();
        $pattern = new \IntlDatePatternGenerator($locale)->getBestPattern($skeleton);
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $day->getTimezone(), null, false === $pattern ? 'd MMM' : $pattern);
        $text = $formatter->format($day);

        return false === $text ? $day->format('Y-m-d') : $text;
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
