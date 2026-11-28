<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Screen;

use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\MoneyFormat;
use Foresi\Ui\Format\MoneyText;
use Foresi\Vat\Application\CalculationStep;
use Foresi\Vat\Application\PaymentView;
use Foresi\Vat\Application\PeriodAmounts;
use Foresi\Vat\Application\PeriodDetail;
use Foresi\Vat\Application\PeriodList;
use Foresi\Vat\Application\PeriodState;
use Foresi\Vat\Application\PeriodView;
use Foresi\Vat\Application\ScheduleAmount;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\PossibleDuplicate;
use Foresi\Vat\Contract\VatPeriod;
use Foresi\Vat\Infrastructure\Controller\VatController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the VAT page shows: the year tabs, one row per period with its
 * amounts and state, the warnings and the unassigned payments above them,
 * and in the side sheet one period's breakdown, documents, steps and
 * payments. A state is an estimate, the amount of the return, settled, or
 * not yet paid (not yet received for a refund): never overdue, never red.
 */
final readonly class VatScreen
{
    /**
     * The badge of each state: neutral or brand, never red (a past period
     * is not yet paid; only the return knows the final amount).
     */
    public const array STATE_TONES = [
        'estimated' => 'outline',
        'return_entered' => 'gray',
        'settled' => 'green',
        'not_yet_paid' => 'gray',
    ];

    /** The gap that says the plans part of every period is not counted yet. */
    public const array PART_GAPS = ['schedules' => 'schedules_not_counted', 'plans' => 'plans_not_counted'];

    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private MoneyText $money,
        private LocaleSwitcher $locales,
    ) {
    }

    /**
     * Last year, this year and next year, as Ui:Tabs takes them.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    public function tabs(int $selected, int $thisYear): array
    {
        return array_map(fn (int $year): array => [
            'label' => (string) $year,
            'href' => $this->urls->generate(VatController::ROUTE, ['year' => $year]),
            'active' => $year === $selected,
        ], [$thisYear - 1, $thisYear, $thisYear + 1]);
    }

    /**
     * One row per period, oldest first.
     *
     * @return list<array{key: string, label: string, output: string, input: string, result: string, resultLabel: string, due: ?string, state: string, stateLabel: string, tone: string, href: string}>
     */
    public function rows(PeriodList $list): array
    {
        return array_map($this->row(...), $list->periods);
    }

    /**
     * @return array{key: string, label: string, output: string, input: string, result: string, resultLabel: string, due: ?string, state: string, stateLabel: string, tone: string, href: string}
     */
    public function row(PeriodView $view): array
    {
        $awaited = $view->awaitedOn();
        [$stateLabel, $tone] = $this->badge($view);

        return [
            'key' => $view->period->key,
            'label' => $this->periodLabel($view->period),
            'output' => $this->amount($view->output),
            'input' => $this->amount($view->input),
            'result' => $this->amount($this->absolute($view->expected)),
            'resultLabel' => $this->trans('vat.page.result.'.$this->direction($view->expected)),
            'due' => $awaited instanceof \DateTimeImmutable ? $this->date($awaited) : null,
            'state' => $view->state->value,
            'stateLabel' => $stateLabel,
            'tone' => $tone,
            'href' => $this->urls->generate(VatController::ROUTE.'_period', ['key' => $view->period->key]),
        ];
    }

    /**
     * The label and the tone of a period's state badge. A period to get back
     * that has not come yet is "not yet received", one to pay "not yet paid".
     *
     * @return array{string, string}
     */
    public function badge(PeriodView $view): array
    {
        $key = $view->state->value;
        if (PeriodState::NotYetPaid === $view->state && $view->expected->isNegative()) {
            $key = 'not_yet_received';
        }

        return [$this->trans('vat.page.state.'.$key), self::STATE_TONES[$view->state->value]];
    }

    /**
     * What the page warns about above the table: the firm's VAT statuses,
     * each period's warnings, the sources that could not be read, and the
     * planned items that may count the VAT twice.
     *
     * @return list<array{key: string, text: string}>
     */
    public function warnings(PeriodList $list): array
    {
        $warnings = [];
        foreach ($list->warnings as $warning) {
            $warnings[] = ['key' => $warning, 'text' => $this->known('vat.page.warning.'.$warning, $warning)];
        }
        $periods = [];
        foreach ($list->periods as $view) {
            foreach ($view->warnings as $warning) {
                $periods[$warning][] = $this->periodLabel($view->period);
            }
        }
        foreach ($periods as $warning => $labels) {
            $warnings[] = ['key' => $warning, 'text' => $this->known('vat.page.warning.'.$warning, $warning, ['periods' => implode(', ', $labels)])];
        }
        if ([] !== $list->unavailableSources) {
            $warnings[] = ['key' => 'unavailable_sources', 'text' => $this->trans('vat.page.warning.unavailable_sources', ['sources' => implode(', ', array_map(fn (string $source): string => $this->known('vat.page.source.'.$source, $source), $list->unavailableSources))])];
        }

        return $warnings;
    }

    /**
     * The planned items of the category VAT that may count the VAT twice,
     * each with where a person ends it.
     *
     * @return list<array{name: string, href: ?string}>
     */
    public function duplicates(PeriodList $list): array
    {
        return array_map(static fn (PossibleDuplicate $item): array => ['name' => $item->name, 'href' => $item->url], $list->duplicates);
    }

    /**
     * The bank movements to or from the tax office that pay no period yet.
     *
     * @return list<array{id: string, on: string, amount: string, direction: string, rule: string, manual: bool}>
     */
    public function unassigned(PeriodList $list): array
    {
        return array_map($this->payment(...), $list->unassigned);
    }

    /**
     * The foot of the page: what is not verified yet and what the amounts
     * do not count yet.
     *
     * @return array{unverified: list<string>, gaps: list<string>}
     */
    public function footer(PeriodList $list): array
    {
        return [
            'unverified' => array_map(fn (string $key): string => $this->known('vat.page.unverified.'.$key, $key), $list->unverified),
            'gaps' => array_map(fn (string $key): string => $this->known('vat.page.gap.'.$key, $key), $list->knownGaps),
        ];
    }

    /**
     * One period in the side sheet: its row, the breakdown by part, the
     * documents and the schedules behind it, the steps, the payments, the
     * difference between what was paid and what was expected, and what a
     * person entered.
     *
     * @return array{row: array{key: string, label: string, output: string, input: string, result: string, resultLabel: string, due: ?string, state: string, stateLabel: string, tone: string, href: string}, title: string, span: string, parts: list<array{key: string, label: string, output: ?string, input: ?string, empty: ?string}>, documents: list<array{label: string, href: ?string, on: string, output: string, input: string, missingCzk: bool}>, schedules: list<array{label: string, href: ?string, on: string, output: string, fxToday: bool, missingCzk: bool}>, restricted: bool, steps: list<array{name: string, label: string, rule: string, amount: string}>, payments: list<array{id: string, on: string, amount: string, direction: string, rule: string, manual: bool}>, difference: ?string, returnAmount: ?string, estimate: string, settledMark: bool, settled: bool, missingCzk: list<string>}
     */
    public function detail(PeriodDetail $detail): array
    {
        $view = $detail->period;

        return [
            'row' => $this->row($view),
            'title' => $this->periodLabel($view->period),
            'span' => $this->trans('vat.page.sheet.span', ['start' => $this->date($view->period->start), 'end' => $this->date($view->period->end)]),
            'parts' => $this->parts($view->amounts, $detail->list->knownGaps),
            'documents' => array_map($this->document(...), $detail->documents),
            'schedules' => array_map($this->schedule(...), $detail->schedules),
            'restricted' => [] !== $detail->restrictedSources,
            'steps' => array_map(fn (CalculationStep $step): array => [
                'name' => $step->name,
                'label' => $this->trans('vat.page.step.'.$step->name.'.title'),
                'rule' => $this->trans('vat.page.step.'.$step->name.'.rule'),
                'amount' => $this->amount($step->amount),
            ], $view->amounts->steps),
            'payments' => array_map($this->payment(...), $view->payments),
            'difference' => $this->difference($view),
            'returnAmount' => $view->returnAmount instanceof Money ? $this->amount($this->absolute($view->returnAmount)) : null,
            'estimate' => $this->amount($this->absolute($view->result)),
            'settledMark' => $view->mark instanceof \Foresi\Vat\Application\SettledMark,
            'settled' => PeriodState::Settled === $view->state,
            'missingCzk' => $view->amounts->missingCzk,
        ];
    }

    /**
     * The difference between what was paid and what the period expects, as
     * plain information, or null before any payment.
     */
    public function difference(PeriodView $view): ?string
    {
        if (!$view->difference instanceof Money || !$view->paid instanceof Money) {
            return null;
        }

        return $this->trans($view->difference->isZero() ? 'vat.page.sheet.difference_none' : 'vat.page.sheet.difference', [
            'paid' => $this->amount($view->paid),
            'expected' => $this->amount($view->expected),
            'difference' => $this->signed($view->difference),
        ]);
    }

    /**
     * The periods a payment can be placed in, newest first, as Ui:Select
     * takes them: the key and its label.
     *
     * @return array<string, string>
     */
    public function periodChoices(PeriodList $list): array
    {
        $choices = [];
        foreach (array_reverse($list->periods) as $view) {
            $choices[$view->period->key] = $this->periodLabel($view->period);
        }

        return $choices;
    }

    /**
     * "listopad 2026" for a month, "Q4 2026" for a quarter.
     */
    public function periodLabel(VatPeriod $period): string
    {
        if (VatPeriodLength::Quarter === $period->length) {
            return $this->trans('vat.period.quarter', ['quarter' => intdiv((int) $period->start->format('n') - 1, 3) + 1, 'year' => $period->start->format('Y')]);
        }
        $formatter = new \IntlDateFormatter($this->locales->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $period->start->getTimezone(), null, 'LLLL y');

        return (string) $formatter->format($period->start);
    }

    /**
     * The documents part, the schedules part and the plans part of a period;
     * a part the module does not count yet says so instead of its amounts.
     *
     * @param list<string> $gaps
     *
     * @return list<array{key: string, label: string, output: ?string, input: ?string, empty: ?string}>
     */
    private function parts(PeriodAmounts $amounts, array $gaps): array
    {
        $parts = [];
        foreach (['documents' => $amounts->documentsPart, 'schedules' => $amounts->schedulesPart, 'plans' => $amounts->plansPart] as $key => $part) {
            $counted = !\in_array(self::PART_GAPS[$key] ?? '', $gaps, true);
            $parts[] = [
                'key' => $key,
                'label' => $this->trans('vat.page.part.'.$key),
                'output' => $counted ? $this->amount($part->output) : null,
                'input' => $counted ? $this->amount($part->input) : null,
                'empty' => $counted ? null : $this->trans('vat.page.part.not_counted'),
            ];
        }

        return $parts;
    }

    /**
     * @return array{label: string, href: ?string, on: string, output: string, input: string, missingCzk: bool}
     */
    private function document(DocumentVat $document): array
    {
        return [
            'label' => $document->label,
            'href' => $document->subject->url,
            'on' => $this->date($document->taxPointOn),
            'output' => $this->amount($document->output),
            'input' => $this->amount($document->input),
            'missingCzk' => $document->missingCzk,
        ];
    }

    /**
     * @return array{label: string, href: ?string, on: string, output: string, fxToday: bool, missingCzk: bool}
     */
    private function schedule(ScheduleAmount $amount): array
    {
        return [
            'label' => $amount->schedule->label,
            'href' => $amount->schedule->subject->url,
            'on' => $this->date($amount->schedule->taxPointOn),
            'output' => $this->amount($amount->output),
            'fxToday' => $amount->fxToday,
            'missingCzk' => $amount->missingCzk,
        ];
    }

    /**
     * @return array{id: string, on: string, amount: string, direction: string, rule: string, manual: bool}
     */
    private function payment(PaymentView $view): array
    {
        $direction = $view->payment->incoming ? 'in' : 'out';

        return [
            'id' => $view->payment->id,
            'on' => $this->date($view->payment->on),
            'amount' => ($view->payment->incoming ? '+' : '').$this->amount($view->payment->amount),
            'direction' => $this->trans('vat.page.payment.'.$direction),
            'rule' => $this->trans('vat.page.payment.rule.'.$view->payment->rule),
            'manual' => $view->manual,
        ];
    }

    private function direction(Money $expected): string
    {
        return match (true) {
            $expected->isNegative() => 'refund',
            $expected->isZero() => 'zero',
            default => 'payable',
        };
    }

    private function absolute(Money $money): Money
    {
        return Money::ofMinor(abs($money->minor()), $money->currency());
    }

    private function signed(Money $money): string
    {
        return ($money->isNegative() ? "\u{2212}" : '+').$this->amount($this->absolute($money));
    }

    /**
     * Whole crowns when there are no hellers, "1 890 Kč"; else exact.
     */
    private function amount(Money $money): string
    {
        $factor = MoneyFormat::factor($money->currency());
        if (0 !== $money->minor() % $factor) {
            return $this->money->shown($money);
        }
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);

        return \sprintf("%s\u{a0}%s", $formatter->format(intdiv($money->minor(), $factor), \NumberFormatter::TYPE_INT64), $this->money->unit($money->currency()));
    }

    /**
     * A day as the locale writes it in full, "28. 12. 2026".
     */
    private function date(\DateTimeImmutable $day): string
    {
        $formatter = new \IntlDateFormatter($this->locales->getLocale(), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, $day->getTimezone());

        return (string) $formatter->format($day);
    }

    /**
     * The translation of a key a country may extend, or the fallback when
     * the screen does not know it yet.
     *
     * @param array<string, string> $parameters
     */
    private function known(string $key, string $fallback, array $parameters = []): string
    {
        $text = $this->trans($key, $parameters);

        return $text === $key ? $fallback : $text;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
