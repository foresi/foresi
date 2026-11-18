<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DateRange;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ViewFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What Ui:FilterBar shows of a list's working filter (spec D11): each condition as
 * a chip on the bar, "Due date: before today ✕" as Linear and Notion show them, and
 * the builder of the sheet, a row a condition, with the fields one can add.
 *
 * A reference names what it points to through its source: the page gives each
 * source its picker's address and a lookup of names. One no longer there shows as
 * unknown and matches nothing (spec D4).
 *
 * @phpstan-type Reference array{url: string, name: \Closure(string): ?string}
 * @phpstan-type FieldView array{key: string, label: string, type: string, ops: array<string, string>, options: array<int|string, string>, url: ?string, blank: array<string, mixed>}
 * @phpstan-type Chip array{label: string, href: string, removeLabel: string}
 */
final readonly class FilterSheet
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param \Closure(ViewFilter): string $href       the list with another working filter
     * @param array<string, Reference>     $references by source
     *
     * @return array{chips: list<Chip>, builder: array{fields: list<FieldView>, rows: list<array<string, mixed>>, kept: list<string>, rules: array<string, string>}}
     */
    public function of(ListDefinition $list, ViewFilter $filter, \Closure $href, array $references = []): array
    {
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
            $label = $this->trans($field->label);
            $text = $this->conditionText($condition, $field, $references);
            $chips[] = [
                'label' => $this->translator->trans('ui.view_filter.chip', ['field' => $label, 'condition' => $text]),
                'href' => $href(new ViewFilter(array_values($others))),
                'removeLabel' => $this->translator->trans('ui.view_filter.remove', ['label' => $label]),
            ];
            if (FilterRows::editable($condition, $field)) {
                $name = FieldType::Reference === $field->type && \is_string($condition->value) ? $this->name($field, $condition->value, $references) : '';
                $rows[] = FilterRows::row($condition, $field) + ['label' => $label, 'text' => $name];
            } else {
                // Sent back as it is, so editing the others keeps it.
                $kept[] = json_encode($condition->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
            }
        }

        return ['chips' => $chips, 'builder' => [
            'fields' => array_values(array_map(fn (Field $field): array => $this->fieldView($field, $references), array_filter($list->fields, static fn (Field $field): bool => FieldType::Text !== $field->type))),
            'rows' => $rows,
            'kept' => $kept,
            'rules' => $this->rules(),
        ]];
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
            $ops[$op] = $this->translator->trans('ui.view_filter.op_label.'.$op);
        }
        $options = [];
        foreach ($field->options as $option) {
            $options[$option] = $this->trans($field->optionLabels[$option] ?? $option);
        }

        return [
            'key' => $field->key,
            'label' => $this->trans($field->label),
            'type' => $field->type->value,
            'ops' => $ops,
            'options' => FieldType::Boolean === $field->type ? ['1' => $this->translator->trans('ui.view_filter.yes'), '0' => $this->translator->trans('ui.view_filter.no')] : $options,
            'url' => null === $field->source ? null : ($references[$field->source]['url'] ?? null),
            'blank' => FilterRows::blank($field) + ['label' => $this->trans($field->label), 'text' => ''],
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
            FieldType::Enum => implode(', ', array_map(fn (mixed $option): string => \is_string($option) ? $this->trans($field->optionLabels[$option] ?? $option) : '', \is_array($value) ? $value : [$value])),
            FieldType::Boolean => $this->translator->trans(true === $value ? 'ui.view_filter.yes' : 'ui.view_filter.no'),
            FieldType::Reference => \is_array($value)
                ? implode(', ', array_map(fn (mixed $id): string => $this->name($field, \is_string($id) ? $id : '', $references), $value))
                : (\is_string($value) ? $this->name($field, $value, $references) : ''),
            FieldType::Text => \is_string($value) ? $value : '',
            FieldType::Number => $this->range($value, $this->number(...)),
            FieldType::Date => $this->range($value, $this->date(...)),
        };
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
            return $value['month'];
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

    private function trans(string $key): string
    {
        return '' === $key ? '' : $this->translator->trans($key);
    }
}
