<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Screen;

use Foresi\CashFlow\Application\PlannedItemRules;
use Foresi\CashFlow\Application\PlannedItemVatRule;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Domain\PlannedItemVat;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurringSchedule;
use Foresi\Ui\Format\MoneyText;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The planned income and the planned costs as the two pages under Money show
 * them: the list, the item in its sheet with its next occurrences, and the
 * values and choices of its form. Pulse keeps income and expenses apart and
 * QuickBooks asks money in or money out first, so the page sets the direction.
 */
final readonly class PlannedItemScreen
{
    /** The occurrences the sheet lists, each with Skip this one. */
    public const int OCCURRENCES = 6;

    /** The routes of the two lists, by direction. */
    public const array LIST_ROUTES = ['in' => 'app_cash_flow_planned_income', 'out' => 'app_cash_flow_planned_costs'];

    public function __construct(
        private InstanceClock $clock,
        private MoneyText $money,
        private LocaleSwitcher $locales,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private PlannedItemVatRule $vat,
    ) {
    }

    public static function listRoute(FlowDirection $direction): string
    {
        return self::LIST_ROUTES[$direction->value];
    }

    /**
     * The route of an item's page, under the page of its direction; "_edit" and "_end" follow it.
     */
    public static function itemRoute(FlowDirection $direction): string
    {
        return self::LIST_ROUTES[$direction->value].'_item';
    }

    public function title(FlowDirection $direction): string
    {
        return $this->trans('cash_flow.planned.'.$direction->value.'.title');
    }

    /**
     * @return list<array<string, mixed>> the columns of Ui:DataTable
     */
    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => $this->trans('cash_flow.planned.column.name'), 'truncate' => true],
            ['key' => 'counterparty', 'label' => $this->trans('cash_flow.planned.field.counterparty'), 'truncate' => true, 'phone' => false, 'wide' => true],
            ['key' => 'category', 'label' => $this->trans('cash_flow.planned.column.category'), 'phone' => false],
            ['key' => 'repeat', 'label' => $this->trans('cash_flow.planned.column.repeat'), 'phone' => false],
            ['key' => 'next', 'label' => $this->trans('cash_flow.planned.column.next')],
            ['key' => 'amount', 'label' => $this->trans('cash_flow.planned.column.amount'), 'align' => 'right'],
            ['key' => 'included', 'label' => $this->trans('cash_flow.planned.column.included'), 'phone' => false],
        ];
    }

    /**
     * @param list<PlannedItem> $items
     *
     * @return list<array<string, mixed>> the rows of Ui:DataTable, the next first and the finished last
     */
    public function rows(array $items): array
    {
        $next = [];
        foreach ($items as $item) {
            $next[$item->id()] = $this->next($item);
        }
        usort($items, static fn (PlannedItem $a, PlannedItem $b): int => [!$next[$a->id()] instanceof \DateTimeImmutable, $next[$a->id()]?->format('Y-m-d'), $a->name()] <=> [!$next[$b->id()] instanceof \DateTimeImmutable, $next[$b->id()]?->format('Y-m-d'), $b->name()]);

        return array_map(function (PlannedItem $item) use ($next): array {
            $on = $next[$item->id()];
            $counterparty = $item->counterparty()?->displayName();

            return [
                'id' => $item->id(),
                // A planned item opens in the side sheet over the list (docs/design/overlays.md).
                'href' => $this->urls->generate(self::itemRoute($item->direction()), ['id' => $item->id()]),
                'label' => $item->name(),
                'secondary' => $this->amount($item).($on instanceof \DateTimeImmutable ? ' · '.$this->day($on) : ''),
                'cells' => [
                    'name' => $item->name(),
                    'counterparty' => $counterparty ?? ['empty' => $this->trans('cash_flow.planned.none.counterparty')],
                    'category' => $this->category($item->category()),
                    'repeat' => $this->repeat($item),
                    'next' => $on instanceof \DateTimeImmutable ? $this->day($on) : ['empty' => $this->trans('cash_flow.planned.none.next')],
                    'amount' => $this->amount($item),
                    // A payer's item without its VAT is marked, so the VAT periods can count it once it is stated.
                    'included' => ['badges' => $this->vat->isMissing($item) ? [$this->included($item), $this->vatMissing()] : [$this->included($item)]],
                ],
            ];
        }, $items);
    }

    /**
     * @return list<array<string, mixed>> the details of Ui:DescriptionList
     */
    public function details(PlannedItem $item): array
    {
        $zone = $this->clock->timezone();
        $recurrence = $item->recurrence($zone);
        $match = $item->matchRule();
        $tolerance = $match->toleranceMinor();

        return array_values(array_filter([
            ['label' => $this->trans('cash_flow.planned.field.amount'), 'value' => $this->amount($item)],
            ['label' => $this->trans('cash_flow.planned.field.vat'), 'value' => $this->vatText($item), 'empty' => $this->trans('cash_flow.planned.none.vat')],
            ['label' => $this->trans('cash_flow.planned.field.category'), 'value' => $this->category($item->category())],
            ['label' => $this->trans('cash_flow.planned.column.repeat'), 'value' => $this->repeat($item)],
            ['label' => $this->trans($recurrence instanceof Recurrence ? 'cash_flow.planned.field.first_on' : 'cash_flow.planned.field.on'), 'value' => $this->day($item->firstOn(), true)],
            $recurrence instanceof Recurrence ? ['label' => $this->trans('cash_flow.planned.field.end'), 'value' => $this->end($recurrence)] : null,
            ['label' => $this->trans('cash_flow.planned.field.counterparty'), 'value' => $item->counterparty()?->displayName(), 'empty' => $this->trans('cash_flow.planned.none.counterparty')],
            ['label' => $this->trans('cash_flow.planned.field.match'), 'value' => $match->isEmpty() ? null : implode(' · ', array_filter([
                $match->counterpartyAccount(),
                null === $match->variableSymbol() ? null : $this->trans('cash_flow.planned.match.variable_symbol', ['symbol' => $match->variableSymbol()]),
                null === $tolerance ? null : $this->trans('cash_flow.planned.match.tolerance', ['amount' => $this->money->shown(Money::ofMinor($tolerance, $item->amount()->currency()))]),
            ], static fn (?string $part): bool => null !== $part)), 'empty' => $this->trans('cash_flow.planned.none.match')],
            ['label' => $this->trans('cash_flow.planned.column.included'), 'value' => $this->included($item)],
        ], static fn (?array $row): bool => null !== $row));
    }

    /**
     * The next occurrences from today, the skipped ones among them, each with
     * the day Skip this one sends.
     *
     * @return list<array{on: string, day: string, skipped: bool}>
     */
    public function occurrences(PlannedItem $item): array
    {
        $today = $this->clock->today();
        $recurrence = $item->recurrence($this->clock->timezone());
        $days = $recurrence instanceof Recurrence
            ? RecurringSchedule::next($recurrence, 0, $today, self::OCCURRENCES)
            : ($item->firstOn()->format('Y-m-d') >= $today->format('Y-m-d') ? [$item->firstOn()] : []);

        return array_map(fn (\DateTimeImmutable $on): array => [
            'on' => $on->format('Y-m-d'),
            'day' => $this->day($on, true),
            'skipped' => $item->isSkipped($on),
        ], $days);
    }

    /**
     * The next occurrence that will happen, or null when none is left.
     */
    public function next(PlannedItem $item): ?\DateTimeImmutable
    {
        $recurrence = $item->recurrence($this->clock->timezone());
        $today = $this->clock->today();
        if (!$recurrence instanceof Recurrence) {
            return $item->firstOn()->format('Y-m-d') >= $today->format('Y-m-d') && !$item->isSkipped($item->firstOn()) ? $item->firstOn() : null;
        }
        // Skipped days are few; looking past as many of them as there are finds the next one.
        foreach (RecurringSchedule::next($recurrence, 0, $today, \count($item->skippedOn()) + 1) as $on) {
            if (!$item->isSkipped($on)) {
                return $on;
            }
        }

        return null;
    }

    /**
     * The values of the form: a new item of the direction, or what the item says.
     *
     * @return array<string, string>
     */
    public function values(FlowDirection $direction, ?PlannedItem $item, string $locale): array
    {
        if (!$item instanceof PlannedItem) {
            return [
                'name' => '', 'category' => FlowDirection::In === $direction ? FlowCategory::Revenue->value : FlowCategory::Other->value,
                'amount' => '', 'currency' => 'CZK', 'on' => $this->clock->today()->format('Y-m-d'),
                'repeat' => '', 'every' => '1', 'lastDayOfMonth' => '', 'end' => RecurrenceEnd::Never->value, 'endsOn' => '', 'endsAfter' => '',
                'counterpartyId' => '', 'counterpartyQuery' => '', 'included' => '1',
                'matchAccount' => '', 'matchVariableSymbol' => '', 'matchTolerance' => '',
                'vatMode' => PlannedVatMode::None->value, 'vatRate' => '',
            ];
        }
        $recurrence = $item->recurrence($this->clock->timezone());
        $match = $item->matchRule();
        $tolerance = $match->toleranceMinor();

        return [
            'name' => $item->name(),
            'category' => $item->category()->value,
            'amount' => $this->field($item->amount(), $locale),
            'currency' => $item->amount()->currency()->value,
            'on' => $item->firstOn()->format('Y-m-d'),
            'repeat' => $recurrence?->unit()->value ?? '',
            'every' => (string) ($recurrence?->every() ?? 1),
            'lastDayOfMonth' => true === $recurrence?->onLastDayOfMonth() ? '1' : '',
            'end' => $recurrence?->end()->value ?? RecurrenceEnd::Never->value,
            'endsOn' => $recurrence?->endOn()?->format('Y-m-d') ?? '',
            'endsAfter' => null === $recurrence?->endAfter() ? '' : (string) $recurrence->endAfter(),
            'counterpartyId' => $item->counterparty()?->id() ?? '',
            'counterpartyQuery' => $item->counterparty()?->displayName() ?? '',
            'included' => $item->isIncluded() ? '1' : '',
            'matchAccount' => $match->counterpartyAccount() ?? '',
            'matchVariableSymbol' => $match->variableSymbol() ?? '',
            'matchTolerance' => null === $tolerance ? '' : $this->field(Money::ofMinor($tolerance, $item->amount()->currency()), $locale),
            'vatMode' => $item->vatMode()->value,
            'vatRate' => null === $item->vatRate() ? '' : $this->rateField($item->vatRate(), $locale),
        ];
    }

    /**
     * The choices of the form's selects.
     *
     * @return array{categories: array<string, string>, currencies: array<string, string>, repeats: array<string, string>, ends: array<string, string>, vatModes: array<string, string>}
     */
    public function options(): array
    {
        $categories = [];
        foreach (FlowCategory::cases() as $category) {
            $categories[$category->value] = $this->category($category);
        }
        $repeats = ['' => $this->trans('cash_flow.planned.repeat.once')];
        foreach (PlannedItemRules::UNITS as $unit) {
            $repeats[$unit] = $this->trans('cash_flow.planned.repeat.unit.'.$unit);
        }
        $ends = [];
        foreach (RecurrenceEnd::cases() as $end) {
            $ends[$end->value] = $this->trans('cash_flow.planned.end.'.$end->value);
        }

        $vatModes = [];
        foreach (PlannedVatMode::cases() as $mode) {
            $vatModes[$mode->value] = $this->trans('cash_flow.planned.vat_mode.'.$mode->value);
        }

        return [
            'categories' => $categories,
            'currencies' => array_combine(PlannedItemRules::CURRENCIES, PlannedItemRules::CURRENCIES),
            'repeats' => $repeats,
            'ends' => $ends,
            'vatModes' => $vatModes,
        ];
    }

    public function repeat(PlannedItem $item): string
    {
        $recurrence = $item->recurrence($this->clock->timezone());
        if (!$recurrence instanceof Recurrence) {
            return $this->trans('cash_flow.planned.repeat.once');
        }
        $text = $this->trans('cash_flow.planned.repeat.every.'.$recurrence->unit()->value, ['every' => $recurrence->every()]);

        return $recurrence->onLastDayOfMonth() ? $text.', '.$this->trans('cash_flow.planned.repeat.last_day') : $text;
    }

    public function amount(PlannedItem $item): string
    {
        return $this->money->shown($item->amount());
    }

    /**
     * "3 Dec", or with the year "3 Dec 2026", as the instance's locale writes it.
     */
    public function day(\DateTimeImmutable $day, bool $year = false): string
    {
        $locale = $this->locales->getLocale();
        $pattern = new \IntlDatePatternGenerator($locale)->getBestPattern($year ? 'dMMMy' : 'dMMM');
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $day->getTimezone(), null, false === $pattern ? 'd MMM' : $pattern);
        $text = $formatter->format($day);

        return false === $text ? $day->format('Y-m-d') : $text;
    }

    public function category(FlowCategory $category): string
    {
        return $this->trans('cash_flow.category.'.$category->value);
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function included(PlannedItem $item): array
    {
        return $item->isIncluded()
            ? ['label' => $this->trans('cash_flow.planned.state.included'), 'tone' => 'green']
            : ['label' => $this->trans('cash_flow.planned.state.left_out'), 'tone' => 'gray'];
    }

    /**
     * "10 000,00 Kč + 21 % VAT 2 100,00 Kč", or null when no VAT is stated.
     */
    private function vatText(PlannedItem $item): ?string
    {
        $vat = $item->vat();
        if (!$vat instanceof PlannedItemVat) {
            return null;
        }

        return $this->trans('cash_flow.planned.vat.'.$item->vatMode()->value, [
            'rate' => $this->rateField((string) $item->vatRate(), $this->locales->getLocale()),
            'net' => $this->money->shown($vat->net),
            'vat' => $this->money->shown($vat->vat),
        ]);
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function vatMissing(): array
    {
        return ['label' => $this->trans('cash_flow.planned.state.vat_missing'), 'tone' => 'amber'];
    }

    /**
     * "12,5" in Czech: a rate as the locale writes its decimals.
     */
    private function rateField(string $rate, string $locale): string
    {
        $symbol = new \NumberFormatter($locale, \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        return str_replace('.', $symbol, $rate);
    }

    private function end(Recurrence $recurrence): string
    {
        return match ($recurrence->end()) {
            RecurrenceEnd::Never => $this->trans('cash_flow.planned.end.never'),
            RecurrenceEnd::OnDate => $this->trans('cash_flow.planned.ends_on', ['day' => $this->day($recurrence->endOn() ?? $recurrence->firstIssueOn(), true)]),
            RecurrenceEnd::AfterCount => $this->trans('cash_flow.planned.ends_after', ['count' => $recurrence->endAfter() ?? 0]),
        };
    }

    /**
     * For a field, without grouping: "1500,00" in Czech, from the decimal the API writes.
     */
    private function field(Money $money, string $locale): string
    {
        $symbol = new \NumberFormatter($locale, \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        return str_replace('.', $symbol, PlannedItemWrites::decimal($money));
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
