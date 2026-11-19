<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * Keeps the rows a checked filter matches, all its conditions joined with AND
 * (spec D4), for a list whose rows a module works out in PHP rather than reads
 * with one query, such as the advances waiting for a tax document. Each operator
 * means what DqlConditions compiles it to: an empty value matches no comparison,
 * "is not" and "does not contain" keep an empty value, a date period is from its
 * first day to the day after its last, and relative dates resolve against today.
 *
 * The module gives each field's value on a row: a string, a bool, a number in the
 * unit the filter states it in, a day as a DateTimeImmutable, or null when empty.
 */
final readonly class RowConditions
{
    /**
     * @template T
     *
     * @param list<T>                    $rows
     * @param \Closure(T, string): mixed $value the value of a field on a row
     *
     * @return list<T>
     */
    public static function filter(array $rows, ViewFilter $filter, ListDefinition $list, \Closure $value, \DateTimeImmutable $today): array
    {
        // No condition keeps every row: the loop below finds none that fails.
        return array_values(array_filter($rows, static function (mixed $row) use ($filter, $list, $value, $today): bool {
            foreach ($filter->conditions as $condition) {
                $field = $list->fields[$condition->field] ?? throw new \LogicException('A checked condition names a field of its list.');
                if (!self::matches($field, $condition, $value($row, $field->key), $today)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private static function matches(Field $field, Condition $condition, mixed $actual, \DateTimeImmutable $today): bool
    {
        $empty = null === $actual || (FieldType::Text === $field->type && '' === $actual) || (FieldType::MultiEnum === $field->type && [] === $actual);

        return match ($condition->op) {
            'is_empty' => $empty,
            'is_not_empty' => !$empty,
            default => match ($field->type) {
                FieldType::Enum, FieldType::Reference => self::choice($condition, $actual),
                FieldType::MultiEnum => self::set($condition, \is_array($actual) ? $actual : []),
                FieldType::Boolean => $actual === $condition->value,
                FieldType::Text => self::text($condition, \is_string($actual) ? $actual : null),
                FieldType::Number => !$empty && self::number($condition, $actual),
                FieldType::Date => $actual instanceof \DateTimeImmutable && self::date($condition, $actual, $today),
            },
        };
    }

    private static function choice(Condition $condition, mixed $actual): bool
    {
        $values = \is_array($condition->value) ? $condition->value : [$condition->value];
        $in = null !== $actual && \in_array($actual, $values, true);

        return \in_array($condition->op, ['is_not', 'is_none_of'], true) ? !$in : $in;
    }

    /**
     * A row holds a list of values: any, all or none of the wanted ones.
     *
     * @param array<mixed> $actual
     */
    private static function set(Condition $condition, array $actual): bool
    {
        $wanted = array_values(array_filter(\is_array($condition->value) ? $condition->value : [$condition->value], \is_string(...)));
        $held = array_intersect($wanted, array_filter($actual, \is_string(...)));

        return match ($condition->op) {
            'has_any_of' => [] !== $held,
            'has_all_of' => \count($held) === \count($wanted),
            'has_none_of' => [] === $held,
            default => throw new \LogicException('A checked operator matches.'),
        };
    }

    private static function text(Condition $condition, ?string $actual): bool
    {
        $wanted = \is_string($condition->value) ? $condition->value : '';
        $contains = null !== $actual && str_contains(mb_strtolower($actual), mb_strtolower($wanted));

        return match ($condition->op) {
            'contains' => $contains,
            'does_not_contain' => !$contains,
            'is' => $actual === $wanted,
            default => throw new \LogicException('A checked operator matches.'),
        };
    }

    private static function number(Condition $condition, mixed $actual): bool
    {
        if ('between' === $condition->op) {
            \assert(\is_array($condition->value));

            return $actual >= $condition->value['from'] && $actual <= $condition->value['to'];
        }

        return match ($condition->op) {
            // 100 and 100.0 are the same amount: compared as numbers, not as types.
            'eq' => 0 === ($actual <=> $condition->value),
            'ne' => 0 !== ($actual <=> $condition->value),
            'lt' => $actual < $condition->value,
            'le' => $actual <= $condition->value,
            'gt' => $actual > $condition->value,
            'ge' => $actual >= $condition->value,
            default => throw new \LogicException('A checked operator matches.'),
        };
    }

    /**
     * A period with "is" covers the whole period; "before" is before it starts and
     * "after" after it ends (spec D5).
     */
    private static function date(Condition $condition, \DateTimeImmutable $actual, \DateTimeImmutable $today): bool
    {
        if ('between' === $condition->op) {
            \assert(\is_array($condition->value));
            [$start, $end] = [DateRange::of($condition->value['from'], $today)->start, DateRange::of($condition->value['to'], $today)->end];
        } else {
            $range = DateRange::of($condition->value, $today);
            [$start, $end] = [$range->start, $range->end];
        }
        $day = new \DateTimeImmutable($actual->format('Y-m-d'), $start->getTimezone());

        return match ($condition->op) {
            'is', 'between' => $day >= $start && $day < $end,
            'before' => $day < $start,
            'on_or_after' => $day >= $start,
            'after' => $day >= $end,
            'on_or_before' => $day < $end,
            default => throw new \LogicException('A checked operator matches.'),
        };
    }
}
