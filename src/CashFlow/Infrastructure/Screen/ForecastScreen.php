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
    /** The view of thirteen weeks, about three months, as Float's weekly view and Agicap's display frequency. */
    public const string WEEKS = 'weeks';
    public const int WEEK_COUNT = 13;

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
     * The forecast of one granularity: twelve calendar months, or thirteen weeks
     * from this one. Weeks are used while a week starts on or before
     * weeks_until, so a day before today leaves none and the months start
     * today; for the weeks the months past the thirteenth week are dropped, and
     * the lowest point and the first day below the minimum are those of the
     * weeks kept.
     *
     * @throws \Foresi\CashFlow\Application\CashFlowRefused
     */
    public function forecast(string $view = self::MONTHS): Forecast
    {
        $today = $this->clock->today();
        if (self::WEEKS !== $view) {
            return $this->forecasts->of(null, 12, $today->modify('-1 day')->format('Y-m-d'));
        }
        $lastWeek = $today->modify('monday this week')->modify(\sprintf('+%d weeks', self::WEEK_COUNT - 1));
        $lastDay = $lastWeek->modify('+6 days');
        $months = ((int) $lastDay->format('Y') - (int) $today->format('Y')) * 12 + (int) $lastDay->format('n') - (int) $today->format('n') + 1;

        return $this->weeksOnly($this->forecasts->of(null, $months, $lastWeek->format('Y-m-d')));
    }

    /**
     * The view a period key belongs to: "2026-W48" is a week, "2026-12" a month.
     */
    public static function viewOf(string $key): string
    {
        return str_contains($key, '-W') ? self::WEEKS : self::MONTHS;
    }

    /**
     * The two views as the tabs over the chart, the one shown marked.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    public function views(string $view): array
    {
        return [
            ['label' => $this->trans('cash_flow.view.weeks'), 'href' => $this->urls->generate('app_cash_flow', ['view' => self::WEEKS]), 'active' => self::WEEKS === $view],
            ['label' => $this->trans('cash_flow.view.months'), 'href' => $this->urls->generate('app_cash_flow'), 'active' => self::WEEKS !== $view],
        ];
    }

    /**
     * What Ui:BalanceChart draws: money in and out and the closing of each
     * period in whole units, with the texts of its tooltip.
     *
     * @return array{periods: list<array<string, mixed>>, minimum: ?int, minimumText: ?string, label: string, legend: array<string, string>}
     */
    public function chart(Forecast $forecast): array
    {
        $periods = [];
        foreach ($forecast->periods as $index => $period) {
            $in = $this->total($period->inflows, $period->opening);
            $out = $this->total($period->outflows, $period->opening);
            $periods[] = [
                'label' => $this->shortLabel($period, 0 === $index),
                'in' => $this->units($in),
                'out' => $this->units($out),
                'closing' => $this->units($period->closing),
                'inText' => $this->whole($in),
                'outText' => $this->whole($out),
                'closingText' => $this->whole($period->closing),
                'below' => $forecast->minimum instanceof Money && $period->closing->minor() < $forecast->minimum->minor(),
            ];
        }
        $weeks = [] !== $forecast->periods && PeriodKind::Week === $forecast->periods[0]->period->kind;

        return [
            'periods' => $periods,
            'minimum' => $forecast->minimum instanceof Money ? $this->units($forecast->minimum) : null,
            'minimumText' => $forecast->minimum instanceof Money ? $this->whole($forecast->minimum) : null,
            'label' => $this->trans($weeks ? 'cash_flow.chart.label_weeks' : 'cash_flow.chart.label_months'),
            'legend' => [
                'in' => $this->trans('cash_flow.table.in'),
                'out' => $this->trans('cash_flow.table.out'),
                'closing' => $this->trans('cash_flow.table.closing'),
                'minimum' => $this->trans('cash_flow.kpi.minimum'),
            ],
        ];
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
     * @return array{columns: list<array{key: string, label: string, detail: string}>, rows: list<array{kind: string, label: string, cells: list<array{text: string, href: ?string, below: bool}>}>}
     */
    public function table(Forecast $forecast): array
    {
        $columns = [];
        foreach ($forecast->periods as $period) {
            $columns[] = ['key' => $period->period->key(), 'label' => $this->columnLabel($period), 'detail' => $this->columnDetail($period)];
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
     * A period under its column: the day a week starts, or the month, short,
     * with its year on the first column and on January ("Nov 26", "Dec", "Jan 27"),
     * short enough for its column in Czech too ("lis 26").
     */
    private function shortLabel(PeriodForecast $period, bool $first): string
    {
        $start = $period->period->start;
        if (PeriodKind::Week === $period->period->kind) {
            return $this->day($start);
        }

        $month = $this->pattern('MMM', $start);

        return $first || '1' === $start->format('n') ? $month."\u{a0}".$start->format('y') : $month;
    }

    /**
     * A column's first line: the week's number ("Week 47") or the month alone
     * ("November", cs "listopad").
     */
    private function columnLabel(PeriodForecast $period): string
    {
        $start = $period->period->start;

        return PeriodKind::Week === $period->period->kind
            ? $this->trans('cash_flow.table.week', ['week' => (int) $start->format('W')])
            : $this->pattern('LLLL', $start);
    }

    /**
     * A column's second line: the days of a week, or of a month shorter than
     * its month such as the first one, which starts today ("Nov 19–30",
     * cs "19.–30. 11."); the year of a whole month.
     */
    private function columnDetail(PeriodForecast $period): string
    {
        $start = $period->period->start;
        $end = $period->period->end;
        if (PeriodKind::Month === $period->period->kind
            && '01' === $start->format('d')
            && $end->format('Y-m-d') === $start->modify('last day of this month')->format('Y-m-d')) {
            return $this->pattern('y', $start);
        }
        if ($start->format('Y-m') !== $end->format('Y-m')) {
            return $this->trans('cash_flow.table.range', ['from' => $this->day($start), 'to' => $this->day($end)]);
        }

        return $this->trans('cash_flow.table.days', [
            'from' => (int) $start->format('j'),
            'to' => (int) $end->format('j'),
            'month' => $start,
        ]);
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
     * The weeks of a forecast without the months after them. The first day
     * under the minimum is kept when it falls in the weeks, and so is the
     * lowest point; a lowest point after them is found again within the weeks,
     * by the balance at the end of each day as Forecasts::of() finds it.
     */
    private function weeksOnly(Forecast $forecast): Forecast
    {
        $periods = array_values(array_filter($forecast->periods, static fn (PeriodForecast $period): bool => PeriodKind::Week === $period->period->kind));
        if ([] === $periods) {
            return $forecast;
        }
        $end = $periods[array_key_last($periods)]->period->end;
        $shortfallOn = $forecast->shortfallOn instanceof \DateTimeImmutable && $forecast->shortfallOn <= $end ? $forecast->shortfallOn : null;
        // The lowest of the whole horizon is the lowest of the weeks when it falls in them.
        if ($forecast->lowestOn <= $end) {
            return $this->withPeriods($forecast, $periods, $forecast->lowest, $forecast->lowestOn, $shortfallOn);
        }
        $running = $periods[0]->opening;
        $lowest = $running;
        $lowestOn = $forecast->from;
        foreach ($periods as $period) {
            foreach ($period->lines as $index => $line) {
                $running = $running->plus($line->signed());
                $next = $period->lines[$index + 1] ?? null;
                if ($next instanceof ForecastLine && $next->countedOn->format('Y-m-d') === $line->countedOn->format('Y-m-d')) {
                    continue;
                }
                if ($running->minor() < $lowest->minor()) {
                    $lowest = $running;
                    $lowestOn = $line->countedOn;
                }
            }
            // The lines the caller may not read still move the balance; the closing holds them.
            $running = $period->closing;
        }

        return $this->withPeriods($forecast, $periods, $lowest, $lowestOn, $shortfallOn);
    }

    /**
     * @param list<PeriodForecast> $periods
     */
    private function withPeriods(Forecast $forecast, array $periods, Money $lowest, \DateTimeImmutable $lowestOn, ?\DateTimeImmutable $shortfallOn): Forecast
    {
        return new Forecast(
            $forecast->firm,
            $forecast->today,
            $forecast->from,
            $forecast->opening,
            $forecast->accounts,
            $forecast->balanceAsOf,
            $forecast->stale,
            $periods,
            $lowest,
            $lowestOn,
            $forecast->minimum,
            $shortfallOn,
            $forecast->unavailableSources,
            $forecast->unavailableRates,
            $forecast->restrictedSources,
        );
    }

    /**
     * @param array<string, Money> $flows by category
     */
    private function total(array $flows, Money $zeroOf): Money
    {
        return array_reduce($flows, static fn (Money $sum, Money $flow): Money => $sum->plus($flow), Money::ofMinor(0, $zeroOf->currency()));
    }

    /**
     * Whole units, rounded half away from zero, as whole() writes them.
     */
    private function units(Money $money): int
    {
        $minor = $money->minor();

        return intdiv(abs($minor) + 50, 100) * ($minor < 0 ? -1 : 1);
    }

    /**
     * Whole crowns, as a forecast is read: "48 176 Kč".
     */
    private function whole(Money $money): string
    {
        $units = $this->units($money);
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
     * @param array<string, string|int|\DateTimeInterface> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
