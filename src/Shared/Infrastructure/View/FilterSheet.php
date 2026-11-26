<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View;

use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DateRange;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Application\View\QuickPick;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What Ui:FilterBar shows of a list's working filter (spec D11): each condition as
 * a chip on the bar, "Due date: before today ✕" as Linear and Notion show them, and
 * the builder of the sheet, a row a condition, with the fields one can add.
 *
 * A reference names what it points to through its source: the page gives each
 * source its picker's address and a lookup of names. One no longer there shows as
 * unknown and matches nothing (spec D4). The contact and the user fields of the
 * custom fields (FieldReferenceNames::CONTACT_SOURCE, USER_SOURCE) pick from the picker
 * of the reference fields and are named by their modules, unless the page gives
 * their sources itself.
 *
 * The pinned fields of the view are its quick filters (Shopify Polaris IndexFilters pins filters to
 * the bar, Linear and Notion keep them with the view): each is a chip under the tabs named by its
 * field, "Field ▾" while nothing is set on it and "Field: value ✕" once it is. Its dropdown is what
 * the field declares (QuickPick): its values, one or several at once where it takes "any of"; the
 * periods and the months with records for a date; a search of the records a reference points to;
 * each pick a link to the list with that condition instead of any it had on the field; a text takes
 * its words in a small field ("contains"). A number, with nothing to list, opens the sheet with a row
 * for it (Linear's filter menu).
 *
 * @phpstan-type Reference array{url: string, name: \Closure(string): ?string}
 * @phpstan-type FieldView array{key: string, label: string, type: string, ops: array<string, string>, options: array<int|string, string>, url: ?string, blank: array<string, mixed>}
 * @phpstan-type Chip array{label: string, href: string, removeLabel: string, field: string, pinned: bool}
 * @phpstan-type QuickOption array{label: string, href: string, checked: bool}
 * @phpstan-type QuickLookup array{url: ?string, base: string, param: string, conditions: string, field: string, op: string, single: bool, value: string, selected: list<array{id: string, label: string, href: string}>}
 * @phpstan-type Quick array{key: string, label: string, pick: string, menu: bool, multi: bool, set: bool, text: string, clearHref: string, groups: list<array{label: ?string, options: list<QuickOption>}>, lookup: QuickLookup|null}
 */
final readonly class FilterSheet
{
    public function __construct(
        private TranslatorInterface $translator,
        private ?UrlGeneratorInterface $urls = null,
        private ?ReferenceNames $names = null,
    ) {
    }

    /** The periods a date's quick filter offers, before its months. */
    public const array QUICK_PERIODS = ['this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year'];
    /** At most this many months follow them, the latest first. */
    public const int QUICK_MONTHS = 24;

    /**
     * @param \Closure(ViewFilter): string $href       the list with another working filter
     * @param array<string, Reference>     $references by source
     * @param list<string>|null            $pinned     the fields pinned as quick filters, in order (WorkingState::$pinned); null for the list's own
     * @param array<string, list<string>>  $months     the months as YYYY-MM a date field's quick filter offers, by field, such as the months with a document
     * @param ListSlot|null                $slot       where the list keeps its state on a page of several tables; null for the page's one
     *
     * @return array{chips: list<Chip>, quick: list<Quick>, pinnable: list<array{key: string, label: string, pinned: bool}>, builder: array{fields: list<FieldView>, rows: list<array<string, mixed>>, kept: list<string>, rules: array<string, string>, param: string, name: string, marker: string}}
     */
    public function of(ListDefinition $list, ViewFilter $filter, \Closure $href, array $references = [], ?array $pinned = null, array $months = [], ?ListSlot $slot = null): array
    {
        $slot ??= ListSlot::first([]);
        $references += $this->fieldReferences();
        $pinned = $list->pinnedOf($pinned);
        $chips = [];
        $rows = [];
        $kept = [];
        foreach ($filter->conditions as $index => $condition) {
            $field = $list->fields[$condition->field] ?? null;
            if (!$field instanceof Field) {
                continue;
            }
            $others = $filter->conditions;
            unset($others[$index]);
            $label = $this->label($field);
            $text = $this->conditionText($condition, $field, $references);
            $chips[] = [
                'label' => $this->translator->trans('ui.view_filter.chip', ['field' => $label, 'condition' => $text]),
                'href' => $href(new ViewFilter(array_values($others))),
                'removeLabel' => $this->translator->trans('ui.view_filter.remove', ['label' => $label]),
                'field' => $field->key,
                'pinned' => \in_array($field->key, $pinned, true),
            ];
            if (FilterRows::editable($condition, $field)) {
                $name = FieldType::Reference === $field->type && \is_string($condition->value) ? $this->name($field, $condition->value, $references) : '';
                $rows[] = FilterRows::row($condition, $field) + ['label' => $label, 'text' => $name];
            } else {
                // Sent back as it is, so editing the others keeps it.
                $kept[] = json_encode($condition->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
            }
        }

        return ['chips' => $chips, 'quick' => $this->quick($list, $filter, $href, $pinned, $months, $references, $slot->field(WorkingFilter::PARAM)), 'pinnable' => $this->pinnable($list, $pinned), 'builder' => [
            'fields' => array_values(array_map(fn (Field $field): array => $this->fieldView($field, $references), $list->fields)),
            'rows' => $rows,
            'kept' => $kept,
            'rules' => $this->rules(),
            // The address parameter of the working filter, which clearing the bar drops.
            'param' => $slot->field(WorkingFilter::PARAM),
            // The names the builder's rows and its marker have in the address (FilterRows).
            'name' => $slot->field(FilterRows::PARAM),
            'marker' => $slot->field(FilterRows::MARKER),
        ]];
    }

    /**
     * The sources of the custom fields' references: the picker of the reference fields,
     * and the names of the module of each kind.
     *
     * @return array<string, Reference>
     */
    private function fieldReferences(): array
    {
        if (!$this->urls instanceof UrlGeneratorInterface || !$this->names instanceof ReferenceNames) {
            return [];
        }
        $references = [];
        foreach ([FieldReferenceNames::CONTACT_SOURCE, FieldReferenceNames::USER_SOURCE] as $source) {
            $kind = ReferenceNames::kindOfSource($source);
            $names = $kind instanceof \Foresi\Shared\Contract\Field\FieldKind ? $this->names->of($kind) : null;
            if ($kind instanceof \Foresi\Shared\Contract\Field\FieldKind && $names instanceof FieldReferenceNames) {
                $references[$source] = [
                    'url' => $this->urls->generate(FieldReferenceNames::ROUTE, ['kind' => $kind->value]),
                    'name' => static fn (string $id): ?string => $names->names([$id])[$id] ?? null,
                ];
            }
        }

        return $references;
    }

    /**
     * The quick filters of the pinned fields, in their order, each as its field declares its pick
     * (QuickPick): a menu of its values, single or several at once; the periods and the months with
     * records; a search of the records it points to; or the sheet with a row for it.
     *
     * @param \Closure(ViewFilter): string $href
     * @param list<string>                 $pinned
     * @param array<string, list<string>>  $months
     * @param array<string, Reference>     $references
     * @param string                       $param      the name of the working filter in the address
     *
     * @return list<Quick>
     */
    private function quick(ListDefinition $list, ViewFilter $filter, \Closure $href, array $pinned, array $months, array $references, string $param): array
    {
        $quick = [];
        foreach ($pinned as $key) {
            $field = $list->fields[$key];
            $set = $filter->on($key);
            $others = new ViewFilter(array_values(array_filter($filter->conditions, static fn (Condition $condition): bool => $condition->field !== $key)));
            $picked = $this->picked($set);
            $pick = $field->quickPick;
            $url = null === $field->source ? null : ($references[$field->source]['url'] ?? null);
            if ($pick->looksUp() && null === $url) {
                $pick = QuickPick::Sheet;
            }
            $multi = QuickPick::Values === $pick && FieldType::Boolean !== $field->type && $field->multiPick() || $pick->looksUp() && $field->multiPick();
            // A link to the list with this field picked as given, instead of what it had.
            $with = fn (array $values): string => $href([] === $values ? $others : $others->with($this->pickCondition($field, array_values($values))));
            $option = function (string $label, mixed $value) use ($with, $picked, $multi): array {
                $checked = \in_array($value, $picked, true);
                $values = $multi ? ($checked ? array_values(array_filter($picked, static fn (mixed $one): bool => $one !== $value)) : [...$picked, $value]) : [$value];

                return ['label' => $label, 'href' => $with($values), 'checked' => $checked];
            };
            $groups = match ($pick) {
                QuickPick::Values => FieldType::Boolean === $field->type
                    ? [['label' => null, 'options' => [$option($this->translator->trans('ui.view_filter.yes'), true), $option($this->translator->trans('ui.view_filter.no'), false)]]]
                    : [['label' => null, 'options' => array_map(fn (string $value): array => $option($this->trans($field->optionLabels[$value] ?? $value), $value), $field->options)]],
                QuickPick::Months => $this->dateGroups($option, $months[$key] ?? []),
                default => [],
            };
            // A text with nothing to list takes its words in a small field under its chip (Linear, Notion).
            $typed = QuickPick::Sheet === $pick && FieldType::Text === $field->type;
            $quick[] = [
                'key' => $key,
                'label' => $this->label($field),
                'pick' => $typed ? 'text' : $pick->value,
                // A field with nothing to list opens the sheet with a row for it.
                'menu' => QuickPick::Sheet !== $pick || $typed,
                'multi' => $multi,
                'set' => [] !== $set,
                'text' => implode(', ', array_map(fn (Condition $condition): string => $this->conditionText($condition, $field, $references), $set)),
                'clearHref' => $href($others),
                'groups' => $groups,
                'lookup' => $pick->looksUp() || $typed ? [
                    'url' => $typed ? null : $url,
                    'base' => $href($others),
                    'param' => $param,
                    'conditions' => json_encode($others->toArray()['conditions'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION),
                    'field' => $key,
                    'op' => $typed ? 'contains' : $field->pickOperator(),
                    'single' => $typed || !$multi,
                    'value' => $typed && 1 === \count($set) && 'contains' === $set[0]->op && \is_string($set[0]->value) ? $set[0]->value : '',
                    'selected' => $typed ? [] : array_values(array_map(fn (string $id): array => ['id' => $id, 'label' => $this->name($field, $id, $references), 'href' => $with(array_values(array_filter($picked, static fn (mixed $one): bool => $one !== $id)))], array_filter($picked, \is_string(...)))),
                ] : null,
            ];
        }

        return $quick;
    }

    /**
     * The values a field's conditions pick: those of "is" and of its "any of".
     *
     * @param list<Condition> $set
     *
     * @return list<mixed>
     */
    private function picked(array $set): array
    {
        if (1 !== \count($set) || !\in_array($set[0]->op, ['is', 'is_any_of', 'has_any_of'], true)) {
            return [];
        }

        return \is_array($set[0]->value) && array_is_list($set[0]->value) ? $set[0]->value : [$set[0]->value];
    }

    /**
     * The condition a quick filter sets: one value is "is", several its "any of"; a set always "has any of".
     *
     * @param non-empty-list<mixed> $values
     */
    private function pickCondition(Field $field, array $values): Condition
    {
        if (FieldType::MultiEnum === $field->type) {
            return new Condition($field->key, 'has_any_of', $values);
        }

        return 1 === \count($values) ? new Condition($field->key, 'is', $values[0]) : new Condition($field->key, 'is_any_of', $values);
    }

    /**
     * The periods, then the months given, newest first.
     *
     * @param \Closure(string, mixed): QuickOption $option
     * @param list<string>                         $months
     *
     * @return list<array{label: ?string, options: list<QuickOption>}>
     */
    private function dateGroups(\Closure $option, array $months): array
    {
        $groups = [['label' => null, 'options' => array_map(fn (string $period): array => $option($this->translator->trans('ui.view_filter.relative.'.$period), ['relative' => $period]), self::QUICK_PERIODS)]];
        $months = array_values(array_unique(array_filter($months, static fn (string $month): bool => 1 === preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/', $month))));
        rsort($months);
        // The latest two years; an older month is picked in the sheet ("Custom…").
        $months = \array_slice($months, 0, self::QUICK_MONTHS);
        if ([] !== $months) {
            $groups[] = ['label' => $this->translator->trans('ui.view_filter.quick.by_month'), 'options' => array_map(fn (string $month): array => $option($this->monthName($month), ['month' => $month]), $months)];
        }

        return $groups;
    }

    /**
     * Every field one can pin, those pinned first in their order, for the Settings sheet.
     *
     * @param list<string> $pinned
     *
     * @return list<array{key: string, label: string, pinned: bool}>
     */
    private function pinnable(ListDefinition $list, array $pinned): array
    {
        $items = [];
        foreach ($pinned as $key) {
            $items[$key] = ['key' => $key, 'label' => $this->label($list->fields[$key]), 'pinned' => true];
        }
        foreach ($list->fields as $field) {
            $items[$field->key] ??= ['key' => $field->key, 'label' => $this->label($field), 'pinned' => false];
        }

        return array_values($items);
    }

    private function monthName(string $month): string
    {
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'LLLL y');

        return (string) $formatter->format(new \DateTimeImmutable($month.'-01', new \DateTimeZone('UTC')));
    }

    /**
     * @param array<string, Reference> $references
     *
     * @return FieldView
     */
    private function fieldView(Field $field, array $references): array
    {
        $ops = [];
        foreach (FilterRows::operators($field) as $op) {
            // A relation reads "has at least one" and "has none" (Airtable, HubSpot).
            $ops[$op] = $this->translator->trans('ui.view_filter.op_label.'.(FieldType::Relation === $field->type ? 'relation.' : '').$op);
        }
        $options = [];
        foreach ($field->options as $option) {
            $options[$option] = $this->trans($field->optionLabels[$option] ?? $option);
        }

        return [
            'key' => $field->key,
            'label' => $this->label($field),
            'type' => $field->type->value,
            'ops' => $ops,
            'options' => FieldType::Boolean === $field->type ? ['1' => $this->translator->trans('ui.view_filter.yes'), '0' => $this->translator->trans('ui.view_filter.no')] : $options,
            'url' => null === $field->source ? null : ($references[$field->source]['url'] ?? null),
            'blank' => FilterRows::blank($field) + ['label' => $this->label($field), 'text' => ''],
        ];
    }

    /**
     * The rules a date end is picked from: a day, a month, the periods, the counts of days.
     *
     * @return array<string, string>
     */
    private function rules(): array
    {
        $rules = [FilterRows::DAY => $this->translator->trans('ui.view_filter.rule.day'), FilterRows::MONTH => $this->translator->trans('ui.view_filter.rule.month')];
        foreach (DateRange::PERIODS as $period) {
            $rules[$period] = $this->translator->trans('ui.view_filter.relative.'.$period);
        }
        foreach (DateRange::COUNTED as $counted) {
            $rules[$counted] = $this->translator->trans('ui.view_filter.rule.'.$counted);
        }

        return $rules;
    }

    /**
     * @param array<string, Reference> $references
     */
    private function conditionText(Condition $condition, Field $field, array $references): string
    {
        $value = $condition->value;
        $text = match ($field->type) {
            FieldType::Enum, FieldType::MultiEnum => implode(', ', array_map(fn (mixed $option): string => \is_string($option) ? $this->trans($field->optionLabels[$option] ?? $option) : '', \is_array($value) ? $value : [$value])),
            FieldType::Boolean => $this->translator->trans(true === $value ? 'ui.view_filter.yes' : 'ui.view_filter.no'),
            FieldType::Reference => \is_array($value)
                ? implode(', ', array_map(fn (mixed $id): string => $this->name($field, \is_string($id) ? $id : '', $references), $value))
                : (\is_string($value) ? $this->name($field, $value, $references) : ''),
            FieldType::Text => \is_string($value) ? $value : '',
            FieldType::Number => $this->range($value, $this->number(...)),
            FieldType::Date => $this->range($value, $this->date(...)),
            FieldType::Relation => '',
        };
        if (FieldType::Relation === $field->type) {
            return $this->translator->trans('ui.view_filter.op_label.relation.'.$condition->op);
        }
        // "Is" reads as the value alone: "Payment state: Unpaid", "Issue date: this month".
        if (\in_array($condition->op, ['is', 'is_any_of'], true)) {
            return $text;
        }

        return $this->translator->trans('ui.view_filter.op.'.$condition->op, ['value' => $text]);
    }

    /**
     * @param \Closure(mixed): string $one
     */
    private function range(mixed $value, \Closure $one): string
    {
        if (\is_array($value) && \array_key_exists('from', $value) && \array_key_exists('to', $value)) {
            return $this->translator->trans('ui.view_filter.range', ['from' => $one($value['from']), 'to' => $one($value['to'])]);
        }

        return $one($value);
    }

    private function number(mixed $value): string
    {
        if (!\is_int($value) && !\is_float($value)) {
            return '';
        }
        $format = new \NumberFormatter($this->translator->getLocale(), \NumberFormatter::DECIMAL);

        return (string) $format->format($value);
    }

    private function date(mixed $value): string
    {
        if (\is_string($value)) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return false === $day ? $value : $day->format($this->translator->trans('format.date'));
        }
        if (\is_array($value) && \is_string($value['month'] ?? null)) {
            return 1 === preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/', $value['month']) ? $this->monthName($value['month']) : $value['month'];
        }
        if (\is_array($value) && \is_string($value['relative'] ?? null)) {
            return $this->translator->trans('ui.view_filter.relative.'.$value['relative'], ['n' => $value['n'] ?? 0]);
        }

        return '';
    }

    /**
     * @param array<string, Reference> $references
     */
    private function name(Field $field, string $id, array $references): string
    {
        $lookup = null === $field->source ? null : ($references[$field->source]['name'] ?? null);

        return (null === $lookup ? null : $lookup($id)) ?? $this->translator->trans('ui.view_filter.unknown');
    }

    /**
     * The name of a field, after its relation's when it reads through one: "Customer: City".
     */
    private function label(Field $field): string
    {
        $label = $this->trans($field->label);

        return null === $field->group ? $label : $this->translator->trans('ui.view_filter.related', ['relation' => $this->trans($field->group), 'field' => $label]);
    }

    private function trans(string $key): string
    {
        return '' === $key ? '' : $this->translator->trans($key);
    }
}
