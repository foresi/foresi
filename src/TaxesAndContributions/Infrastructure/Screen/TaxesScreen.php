<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Screen;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\CreditLine;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\FlatRateResult;
use Foresi\TaxesAndContributions\Contract\ForecastCertainty;
use Foresi\TaxesAndContributions\Contract\ForecastView;
use Foresi\TaxesAndContributions\Contract\MethodResult;
use Foresi\TaxesAndContributions\Contract\PossibleDuplicate;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Contract\Step;
use Foresi\TaxesAndContributions\Contract\Totals;
use Foresi\TaxesAndContributions\Infrastructure\Controller\TaxesController;
use Foresi\Ui\Format\MoneyText;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the Taxes and contributions page shows: the year tabs, what the owner
 * keeps with one expense method, the payments as they fall due, the methods
 * side by side, the credits, and
 * the steps of each number as the side sheet lists them. Every text is
 * translated by its key; the English sentences of the regime are for the API.
 */
final readonly class TaxesScreen
{
    /** Every levy and the take-home, in the order of the summary; the flat rate only in a year that has it. */
    private const array SUMMARY = ['income', 'costs', 'social', 'health', 'sickness', 'income_tax', 'flat_rate', 'child_bonus', 'levies', 'take_home'];

    /** The rows of the comparison of the expense methods. */
    private const array COMPARISON = ['profit', 'levies', 'take_home'];

    /** The steps a number of the page opens: its own, or the ones it is made of. */
    public const array CHAINS = [
        Step::PROFIT => [Step::PROFIT],
        Step::SOCIAL => [Step::SOCIAL],
        Step::HEALTH => [Step::HEALTH],
        Step::SICKNESS => [Step::SICKNESS],
        Step::INCOME_TAX => [Step::TAX_BASE, Step::INCOME_TAX, Step::CREDITS, Step::CHILD_BENEFIT],
        Step::CHILD_BENEFIT => [Step::CHILD_BENEFIT],
        Step::FLAT_RATE => [Step::FLAT_RATE],
        self::ALL => Step::KEYS,
    ];

    /** The whole calculation: what the levies and the take-home are made of. */
    public const string ALL = 'all';

    /** The number of each row and the steps it opens. */
    private const array OPENS = [
        'income' => Step::PROFIT,
        'costs' => Step::PROFIT,
        'profit' => Step::PROFIT,
        'social' => Step::SOCIAL,
        'health' => Step::HEALTH,
        'sickness' => Step::SICKNESS,
        'income_tax' => Step::INCOME_TAX,
        'child_bonus' => Step::CHILD_BENEFIT,
        'flat_rate' => Step::FLAT_RATE,
        'levies' => self::ALL,
        'take_home' => self::ALL,
    ];

    /** The badge of each state of a payment: a fact paid, an expectation, or late. */
    private const array STATE_TONES = [DuePayment::STATE_PAID => 'green', DuePayment::STATE_EXPECTED => 'outline', DuePayment::STATE_OVERDUE => 'red'];

    /** Inputs that are a rate in per cent. */
    private const array PERCENT_INPUTS = ['rate', 'higher_rate', 'lump_sum_rate'];

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
            'href' => $this->urls->generate(TaxesController::ROUTE, ['year' => $year]),
            'active' => $year === $selected,
        ], [$thisYear - 1, $thisYear, $thisYear + 1]);
    }

    /**
     * What the owner keeps to date with the method chosen in the profile, or
     * the cheaper one when none is chosen; null when "to date" has no result.
     *
     * The year end beside it uses the same method, and its numbers open the
     * steps of the year end.
     *
     * The flat-rate tax has its own row in a year with flat-rate months.
     *
     * @return ?array{method: string, why: string, rows: list<array{key: string, label: string, text: string, href: string, total: bool, yearEnd: ?array{text: string, href: string}}>}
     */
    public function summary(EstimateView $estimate, ?ExpenseMethod $chosen): ?array
    {
        $toDate = $estimate->toDate;
        if (!$toDate instanceof RegimeResult) {
            return null;
        }
        $result = $this->shown($toDate, $chosen);
        $yearEnd = $estimate->yearEnd instanceof RegimeResult ? $this->shown($estimate->yearEnd, $result->method) : null;
        $rows = [];
        $flatRate = $toDate->flatRate instanceof FlatRateResult || $estimate->yearEnd?->flatRate instanceof FlatRateResult;
        foreach (self::SUMMARY as $key) {
            if ('flat_rate' === $key && !$flatRate) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => $this->trans('taxes.page.row.'.$key),
                'text' => $this->amount($this->total($result->totals, $key)),
                'href' => $this->stepsHref($estimate->year, $result->method, self::OPENS[$key]),
                'total' => \in_array($key, ['levies', 'take_home'], true),
                'yearEnd' => $yearEnd instanceof MethodResult ? [
                    'text' => $this->amount($this->total($yearEnd->totals, $key)),
                    'href' => $this->stepsHref($estimate->year, $yearEnd->method, self::OPENS[$key], true),
                ] : null,
            ];
        }

        return [
            'method' => $this->methodLabel($result->method),
            'why' => $this->trans($result->method === $chosen ? 'taxes.page.summary.chosen' : 'taxes.page.summary.cheaper'),
            'rows' => $rows,
        ];
    }

    /**
     * The expense methods side by side, the cheaper marked, with the
     * break-even sentence; null with fewer than two methods.
     *
     * @return ?array{sentence: ?string, columns: list<array{key: string, label: string, cheaper: bool}>, rows: list<array{key: string, label: string, cells: list<array{text: string, href: string}>}>}
     */
    public function comparison(EstimateView $estimate): ?array
    {
        $toDate = $estimate->toDate;
        if (!$toDate instanceof RegimeResult || \count($toDate->methods) < 2) {
            return null;
        }
        $columns = array_map(fn (MethodResult $result): array => [
            'key' => $result->method->value,
            'label' => $this->methodLabel($result->method),
            'cheaper' => $result->method === $toDate->cheaperMethod,
        ], $toDate->methods);
        $rows = [];
        foreach (self::COMPARISON as $key) {
            $rows[] = [
                'key' => $key,
                'label' => $this->trans('taxes.page.row.'.$key),
                'cells' => array_map(fn (MethodResult $result): array => [
                    'text' => $this->amount($this->total($result->totals, $key)),
                    'href' => $this->stepsHref($estimate->year, $result->method, self::OPENS[$key]),
                ], $toDate->methods),
            ];
        }

        return ['sentence' => $this->breakEven($toDate), 'columns' => $columns, 'rows' => $rows];
    }

    /**
     * The forecast part of the year end by certainty, as the records count
     * it: income and costs of the open documents, the recurring invoices and
     * the plans, with what the forecast could not split; null without one.
     *
     * @return ?array{rows: list<array{key: string, label: string, income: string, costs: string}>, notes: list<string>}
     */
    public function forecast(EstimateView $estimate): ?array
    {
        $forecast = $estimate->forecast;
        if (!$forecast instanceof ForecastView) {
            return null;
        }
        $rows = array_map(fn (ForecastCertainty $certainty): array => [
            'key' => $certainty->value,
            'label' => $this->trans('taxes.page.forecast.certainty.'.$certainty->value),
            'income' => $this->amount($forecast->totalOfCertainty($certainty, AmountKind::Income)->counted),
            'costs' => $this->amount($forecast->totalOfCertainty($certainty, AmountKind::Cost)->counted),
        ], ForecastCertainty::cases());

        return ['rows' => $rows, 'notes' => $this->notes('flag', $forecast->flags)];
    }

    /**
     * The payments of the year by due day, each with its state: expected,
     * paid on the day the bank booked it, or overdue. An overpayment comes in.
     *
     * @return list<array{key: string, due: string, label: string, amount: string, incoming: bool, state: string, stateLabel: string, tone: string}>
     */
    public function payments(EstimateView $estimate): array
    {
        return array_map(fn (DuePayment $payment): array => [
            'key' => $payment->key(),
            'due' => $this->date($payment->dueOn),
            'label' => $this->trans('taxes.flow.'.$payment->kind, ['authority' => $this->trans('taxes.authority.'.$payment->authority->value), 'period' => $payment->periodLabel()]),
            'amount' => ($payment->comesIn() ? '+' : '').$this->amount($payment->amount),
            'incoming' => $payment->comesIn(),
            'state' => $payment->state,
            'stateLabel' => $payment->paidOn instanceof \DateTimeImmutable
                ? $this->trans('taxes.page.payments.paid_on', ['day' => $this->date($payment->paidOn)])
                : $this->trans('taxes.page.payments.state.'.$payment->state),
            'tone' => self::STATE_TONES[$payment->state] ?? 'gray',
        ], $estimate->payments);
    }

    /**
     * The planned items of Cash flow that the payments may count twice (B5),
     * each with where the owner ends it.
     *
     * @return list<array{name: string, href: ?string}>
     */
    public function duplicates(EstimateView $estimate): array
    {
        return array_map(static fn (PossibleDuplicate $item): array => ['name' => $item->name, 'href' => $item->url], $estimate->possibleDuplicates);
    }

    /**
     * Each credit of the rule set, claimed or not, with what it gives and who
     * may claim it.
     *
     * @return list<array{key: string, label: string, claimed: bool, amount: ?string, explanation: string}>
     */
    public function credits(EstimateView $estimate): array
    {
        return array_map(fn (CreditLine $credit): array => [
            'key' => $credit->key,
            'label' => $this->known('taxes.page.credit.'.$credit->key.'.label', $credit->key),
            'claimed' => $credit->claimed,
            'amount' => $credit->claimed ? $this->amount($credit->amount) : null,
            'explanation' => $this->known('taxes.page.credit.'.$credit->key.'.explanation', $credit->explanation, $this->amountsIn($credit->explanation)),
        ], $estimate->toDate->credits ?? []);
    }

    /**
     * What the estimate says beside its numbers, translated by key: the
     * warnings, the points not yet verified and the known gaps.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    public function notes(string $kind, array $keys): array
    {
        return array_map(fn (string $key): string => $this->known('taxes.page.'.$kind.'.'.$key, $key), $keys);
    }

    /**
     * The foot of the page: the rules of which year it counts with and how
     * sure they are, and what is not verified or not counted yet.
     *
     * @return array{rules: ?string, unverified: list<string>, gaps: list<string>}
     */
    public function footer(EstimateView $estimate): array
    {
        $toDate = $estimate->toDate;

        return [
            'rules' => $toDate instanceof RegimeResult ? $this->trans('taxes.page.footer.rules', ['year' => $toDate->ruleYear, 'status' => $toDate->rules->value]) : null,
            'unverified' => $this->notes('unverified', $estimate->unverified),
            'gaps' => $this->notes('gap', $estimate->knownGaps),
        ];
    }

    public function stepsTitle(string $chain, ExpenseMethod $method, bool $yearEnd = false): string
    {
        return $this->trans($yearEnd ? 'taxes.page.steps.title_year_end' : 'taxes.page.steps.title', ['item' => $this->trans('taxes.page.chain.'.$chain), 'method' => $this->methodLabel($method)]);
    }

    /**
     * The steps of one method, as the sheet lists them; a step the method
     * does not have, such as the flat rate of an ordinary year, is left out.
     *
     * @param list<string> $keys
     *
     * @return list<array{key: string, title: string, input: list<array{label: string, value: string}>, rule: string, source: string, result: string}>
     */
    public function steps(MethodResult $result, array $keys): array
    {
        return array_map(function (string $key) use ($result): array {
            $step = $result->step($key);
            $input = [];
            foreach ($step->input as $name => $value) {
                $input[] = ['label' => $this->known('taxes.page.input.'.$name, $name), 'value' => $this->inputValue($name, $value)];
            }

            return [
                'key' => $key,
                'title' => $this->trans('taxes.page.step.'.$key.'.title'),
                'input' => $input,
                // A levy the flat-rate tax covers in a year of flat-rate months only says so.
                'rule' => isset($step->input['flat_rate_months']) ? $this->trans('taxes.page.step.flat_rate.covered') : $this->trans('taxes.page.step.'.$key.'.rule', $this->ruleArguments($step)),
                'source' => $step->sourceUrl,
                'result' => $this->decimal($step->result),
            ];
        }, array_values(array_filter($keys, $result->hasStep(...))));
    }

    private function shown(RegimeResult $toDate, ?ExpenseMethod $chosen): MethodResult
    {
        foreach ([$chosen, $toDate->cheaperMethod] as $method) {
            foreach ($toDate->methods as $result) {
                if ($method === $result->method) {
                    return $result;
                }
            }
        }

        return $toDate->methods[0];
    }

    private function total(Totals $totals, string $key): Money
    {
        return match ($key) {
            'income' => $totals->income,
            'costs' => $totals->costs,
            'profit' => $totals->profit,
            'social' => $totals->social,
            'health' => $totals->health,
            'sickness' => $totals->sickness,
            'income_tax' => $totals->incomeTax,
            'child_bonus' => $totals->childBonus,
            'flat_rate' => $totals->flatRate,
            'levies' => $totals->levies,
            default => $totals->takeHome,
        };
    }

    /**
     * "Actual costs would cost as much as the 60 % lump sum with 700,000 CZK
     * more of them", or that they already cost less.
     */
    private function breakEven(RegimeResult $toDate): ?string
    {
        if (!$toDate->breakEven instanceof Money) {
            return null;
        }
        $lump = null;
        foreach ($toDate->methods as $result) {
            if (ExpenseMethod::Actual !== $result->method && (null === $lump || $result->totals->takeHome->minor() > $lump->totals->takeHome->minor())) {
                $lump = $result;
            }
        }
        if (null === $lump) {
            return null;
        }
        $method = $this->methodLabel($lump->method);

        return $toDate->breakEven->minor() > 0
            ? $this->trans('taxes.page.comparison.break_even', ['amount' => $this->amount($toDate->breakEven), 'method' => $method])
            : $this->trans('taxes.page.comparison.actual_cheaper', ['method' => $method]);
    }

    private function stepsHref(int $year, ExpenseMethod $method, string $chain, bool $yearEnd = false): string
    {
        $parameters = ['year' => $year, 'method' => $method->value, 'step' => $chain];

        return $this->urls->generate(TaxesController::ROUTE.'_steps', $yearEnd ? [...$parameters, 'part' => TaxesController::YEAR_END] : $parameters);
    }

    /**
     * The arguments the translated rule of a step names, from the step's own
     * input, so its numbers are those of the rule set.
     *
     * @return array<string, string>
     */
    private function ruleArguments(Step $step): array
    {
        $input = $step->input;

        return [
            'method' => ExpenseMethod::Actual->value === ($input['method'] ?? null) ? 'actual' : 'lump',
            'counted' => isset($input['base']) ? 'yes' : 'no',
            'rate' => isset($input['lump_sum_rate']) ? $this->percent($input['lump_sum_rate']) : $this->percent($input['rate'] ?? '0'),
            'higher' => $this->percent($input['higher_rate'] ?? '0'),
            'cap' => $this->decimal($input['lump_sum_cap'] ?? '0'),
            'threshold' => $this->decimal($input['threshold'] ?? '0'),
        ];
    }

    private function inputValue(string $name, string $value): string
    {
        if (\in_array($name, self::PERCENT_INPUTS, true)) {
            return $this->percent($value);
        }
        if ('method' === $name) {
            return $this->methodLabel(ExpenseMethod::tryFrom($value) ?? ExpenseMethod::Actual);
        }
        if (\in_array($value, ['yes', 'no'], true)) {
            return $this->trans('taxes.page.'.$value);
        }

        return (string) preg_replace_callback('/-?\d+\.\d{2}\b/', fn (array $match): string => $this->decimal($match[0]), $value);
    }

    /**
     * The amounts of a regime's English sentence ("30,840 CZK"), in their
     * order, for its translation as amount1, amount2 and so on.
     *
     * @return array<string, string>
     */
    private function amountsIn(string $sentence): array
    {
        preg_match_all('/(\d{1,3}(?:,\d{3})*) CZK/', $sentence, $matches);
        $arguments = [];
        foreach ($matches[1] as $index => $number) {
            $arguments['amount'.($index + 1)] = $this->decimal(str_replace(',', '', $number).'.00');
        }

        return $arguments;
    }

    private function decimal(string $value): string
    {
        return $this->amount(Money::of($value, Currency::CZK));
    }

    /**
     * Whole crowns when there are no hellers, "128 480 Kč"; else exact.
     */
    private function amount(Money $money): string
    {
        if (0 !== $money->minor() % 100) {
            return $this->money->shown($money);
        }
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);

        return \sprintf("%s\u{a0}%s", $formatter->format(intdiv($money->minor(), 100), \NumberFormatter::TYPE_INT64), $this->money->unit($money->currency()));
    }

    /**
     * A day as the locale writes it in full, "8. 1. 2027".
     */
    private function date(\DateTimeImmutable $day): string
    {
        $formatter = new \IntlDateFormatter($this->locales->getLocale(), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, $day->getTimezone());

        return (string) $formatter->format($day);
    }

    private function percent(string $value): string
    {
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return \sprintf("%s\u{a0}%%", $formatter->format((float) $value));
    }

    private function methodLabel(ExpenseMethod $method): string
    {
        return $this->trans('taxes.method.'.$method->value);
    }

    /**
     * The translation of a key the regime may extend, or the fallback when
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
