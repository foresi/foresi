<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * The rows of the filter builder (spec D11) as a GET form sends them, and back: one
 * row a condition, its field, its operator and a value whose controls the field's
 * type decides. The form works without JavaScript, so it is read leniently: a row
 * left without an operator, or with a value it cannot take, is dropped rather than
 * failing the others.
 *
 * An enum is ticked as Linear's filters are: "is" with the values ticked, which is
 * "is any of" once more than one is (and "is not" "is none of"). A date end is a
 * rule (a day, a month, a period or a count of days, spec D5) with its day, month
 * or count.
 *
 * @phpstan-type DateEnd array{rule: string, day: string, month: string, n: string}
 * @phpstan-type Row array{field: string, op: string, values: list<string>, value: string, from: string|DateEnd, to: string|DateEnd}
 */
final readonly class FilterRows
{
    /** The rows a form sends, under this name: c[0][field], c[0][op], … */
    public const string PARAM = 'c';

    /** Sent with the rows, so a form with none left clears every condition. */
    public const string MARKER = 'conditions';

    /** The rules of a date end besides the relative ones: a day or a month. */
    public const string DAY = 'day';
    public const string MONTH = 'month';

    /** A row that sends a condition back whole, as JSON. */
    public const string RAW = 'raw';

    /**
     * @param array<mixed> $rows
     */
    public static function parse(array $rows, ListDefinition $list): ViewFilter
    {
        $conditions = [];
        foreach ($rows as $row) {
            $condition = \is_array($row) ? self::condition($row, $list) : null;
            if ($condition instanceof Condition && \count($conditions) < ViewFilter::MAX_CONDITIONS) {
                $conditions[] = $condition;
            }
        }

        return new ViewFilter($conditions);
    }

    /**
     * The operators the builder offers for a field: an enum's "is" and "is not" tick
     * several values; a reference is picked one at a time.
     *
     * @return list<string>
     */
    public static function operators(Field $field): array
    {
        return match ($field->type) {
            FieldType::Enum => array_values(array_filter(['is', 'is_not'], static fn (string $op): bool => [] !== array_intersect($field->operators, 'is' === $op ? ['is', 'is_any_of'] : ['is_not', 'is_none_of']))),
            FieldType::Reference => array_values(array_diff($field->operators, ['is_any_of'])),
            default => $field->operators,
        };
    }

    /**
     * Whether the builder can show a condition as a row; one it cannot (a reference
     * to many, from the API) keeps its place as it is.
     */
    public static function editable(Condition $condition, Field $field): bool
    {
        return FieldType::Text !== $field->type && !(FieldType::Reference === $field->type && 'is_any_of' === $condition->op);
    }

    /**
     * A condition as the row of the form that sends it back unchanged.
     *
     * @return Row
     */
    public static function row(Condition $condition, Field $field): array
    {
        $value = $condition->value;
        $row = ['field' => $field->key, 'op' => $condition->op, 'values' => [], 'value' => '', 'from' => '', 'to' => ''];
        $between = 'between' === $condition->op && \is_array($value);
        switch ($field->type) {
            case FieldType::Enum:
                $row['op'] = \in_array($condition->op, ['is_not', 'is_none_of'], true) ? 'is_not' : 'is';
                $row['values'] = array_values(array_filter(\is_array($value) ? $value : [$value], \is_string(...)));
                break;
            case FieldType::Boolean:
                $row['value'] = true === $value ? '1' : '0';
                break;
            case FieldType::Reference:
            case FieldType::Text:
                $row['value'] = \is_string($value) ? $value : '';
                break;
            case FieldType::Number:
                $row['from'] = self::number($between ? $value['from'] : $value);
                $row['to'] = $between ? self::number($value['to']) : '';
                break;
            case FieldType::Date:
                $row['from'] = self::dateEnd($between ? $value['from'] : $value);
                $row['to'] = $between ? self::dateEnd($value['to']) : self::dateEnd(null);
                break;
        }

        return $row;
    }

    /**
     * The row of a field with nothing set yet: the form without JavaScript holds one per
     * field, its operator left empty until one is picked.
     *
     * @return Row
     */
    public static function blank(Field $field): array
    {
        $end = FieldType::Date === $field->type ? self::dateEnd(null) : '';

        return ['field' => $field->key, 'op' => '', 'values' => [], 'value' => '', 'from' => $end, 'to' => $end];
    }

    /**
     * @param array<mixed> $row
     */
    private static function condition(array $row, ListDefinition $list): ?Condition
    {
        // A condition the builder shows as it is (FilterSheet), sent back whole.
        if (isset($row[self::RAW])) {
            try {
                $data = json_decode(self::text($row[self::RAW]), true, 8, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }

            return \is_array($data) && \is_string($data['field'] ?? null) && \is_string($data['op'] ?? null) ? self::checked(new Condition($data['field'], $data['op'], $data['value'] ?? null), $list) : null;
        }
        $field = $list->fields[self::text($row['field'] ?? null)] ?? null;
        $op = self::text($row['op'] ?? null);
        if (!$field instanceof Field || '' === $op) {
            return null;
        }
        if (\in_array($op, ['is_empty', 'is_not_empty'], true)) {
            return self::checked(new Condition($field->key, $op), $list);
        }
        $value = null;
        switch ($field->type) {
            case FieldType::Enum:
                $ticked = \is_array($row['values'] ?? null) ? array_map(self::text(...), $row['values']) : [];
                // In the order the field lists them, so the same choice is the same filter.
                $values = array_values(array_intersect($field->options, $ticked));
                if ([] === $values) {
                    return null;
                }
                $negated = \in_array($op, ['is_not', 'is_none_of'], true);
                [$op, $value] = 1 === \count($values) ? [$negated ? 'is_not' : 'is', $values[0]] : [$negated ? 'is_none_of' : 'is_any_of', $values];
                break;
            case FieldType::Boolean:
                $value = match (self::text($row['value'] ?? null)) {
                    '1' => true,
                    '0' => false,
                    default => null,
                };
                break;
            case FieldType::Reference:
            case FieldType::Text:
                $value = trim(self::text($row['value'] ?? null));
                break;
            case FieldType::Number:
                $value = self::range($op, self::parseNumber($row['from'] ?? null), self::parseNumber($row['to'] ?? null));
                break;
            case FieldType::Date:
                $value = self::range($op, self::parseDate($row['from'] ?? null), self::parseDate($row['to'] ?? null));
                break;
        }
        if (null === $value || '' === $value) {
            return null;
        }

        return self::checked(new Condition($field->key, $op, $value), $list);
    }

    private static function range(string $op, mixed $from, mixed $to): mixed
    {
        if ('between' !== $op) {
            return $from;
        }

        return null === $from || null === $to ? null : ['from' => $from, 'to' => $to];
    }

    private static function checked(Condition $condition, ListDefinition $list): ?Condition
    {
        try {
            ViewFilter::all($condition)->check($list);
        } catch (InvalidFilter) {
            return null;
        }

        return $condition;
    }

    /**
     * An amount as people type it: "1 250,50" or "1250.5".
     */
    private static function parseNumber(mixed $text): int|float|null
    {
        $text = str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], self::text($text));
        if (!is_numeric($text)) {
            return null;
        }
        $number = (float) $text;

        return floor($number) === $number && abs($number) < \PHP_INT_MAX ? (int) $number : $number;
    }

    /**
     * @return string|array<string, string|int>|null
     */
    private static function parseDate(mixed $end): string|array|null
    {
        if (!\is_array($end)) {
            return null;
        }
        $rule = self::text($end['rule'] ?? null);
        if (self::DAY === $rule) {
            $day = self::text($end['day'] ?? null);

            return '' === $day ? null : $day;
        }
        if (self::MONTH === $rule) {
            $month = self::text($end['month'] ?? null);

            return '' === $month ? null : ['month' => $month];
        }
        if (\in_array($rule, DateRange::COUNTED, true)) {
            $n = filter_var(self::text($end['n'] ?? null), \FILTER_VALIDATE_INT);

            return false === $n ? null : ['relative' => $rule, 'n' => $n];
        }

        return \in_array($rule, DateRange::PERIODS, true) ? ['relative' => $rule] : null;
    }

    private static function number(mixed $value): string
    {
        return \is_int($value) || \is_float($value) ? (string) $value : '';
    }

    /**
     * @return DateEnd
     */
    private static function dateEnd(mixed $value): array
    {
        $end = ['rule' => 'today', 'day' => '', 'month' => '', 'n' => ''];
        if (\is_string($value)) {
            return ['rule' => self::DAY, 'day' => $value] + $end;
        }
        if (\is_array($value) && \is_string($value['month'] ?? null)) {
            return ['rule' => self::MONTH, 'month' => $value['month']] + $end;
        }
        if (\is_array($value) && \is_string($value['relative'] ?? null)) {
            return ['rule' => $value['relative'], 'n' => \is_int($value['n'] ?? null) ? (string) $value['n'] : ''] + $end;
        }

        return $end;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
