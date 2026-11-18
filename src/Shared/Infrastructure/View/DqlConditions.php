<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DateRange;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * Turns a checked filter into a DQL predicate, all its conditions joined with AND
 * (spec D4). The module is the query adapter (D3): it maps each field it stores to
 * the expression of its column, and compiles the derived ones, such as an invoice's
 * payment state, itself; the shared operators of every type are compiled here, and
 * relative dates resolve against the day given, the instance's today.
 *
 * Each compile names its parameters from its prefix, so one count query can hold
 * the predicates of many views side by side (D9).
 *
 * @phpstan-type Parameters array<string, array{mixed, string|ArrayParameterType|null}>
 * @phpstan-type Derived \Closure(Condition, string): array{string, Parameters}
 */
final readonly class DqlConditions
{
    /**
     * @param array<string, string> $columns the DQL expression of each stored field
     * @param Derived               $derived the predicate of a field with no column, given a unique suffix
     * @param array<string, int>    $scales  what a number field's value is multiplied by to compare with its column, such as
     *                                       100 for an amount in whole units stored in minor ones; the column is compared as it is
     */
    public function __construct(
        private ListDefinition $list,
        private array $columns,
        private \Closure $derived,
        private array $scales = [],
    ) {
    }

    /**
     * @param \Closure(): \DateTimeImmutable $today asked only when a date condition needs it
     *
     * @return array{string, Parameters} "1 = 1" when the filter holds no condition
     */
    public function compile(ViewFilter $filter, \Closure $today, string $prefix): array
    {
        $parts = [];
        $parameters = [];
        foreach ($filter->conditions as $index => $condition) {
            $name = $prefix.'_'.$index;
            $column = $this->columns[$condition->field] ?? null;
            [$dql, $bound] = null === $column ? ($this->derived)($condition, $name) : $this->stored($condition, $column, $today, $name);
            $parts[] = '('.$dql.')';
            $parameters += $bound;
        }

        return [[] === $parts ? '1 = 1' : implode(' AND ', $parts), $parameters];
    }

    /**
     * A derived enum: the predicate of each value, joined for the operator.
     *
     * @param array<string, string> $predicates by value
     */
    public static function derivedEnum(Condition $condition, array $predicates): string
    {
        $values = \is_array($condition->value) ? $condition->value : [$condition->value];
        $any = '('.implode(' OR ', array_map(static fn (mixed $value): string => $predicates[\is_string($value) ? $value : ''] ?? throw new \LogicException('A checked value has its predicate.'), $values)).')';

        return \in_array($condition->op, ['is_not', 'is_none_of'], true) ? 'NOT '.$any : $any;
    }

    /**
     * A derived boolean: its predicate when true, the opposite when false.
     */
    public static function derivedBoolean(Condition $condition, string $whenTrue): string
    {
        return true === $condition->value ? '('.$whenTrue.')' : 'NOT ('.$whenTrue.')';
    }

    /**
     * @param \Closure(): \DateTimeImmutable $today
     *
     * @return array{string, Parameters}
     */
    private function stored(Condition $condition, string $column, \Closure $today, string $name): array
    {
        $field = $this->list->fields[$condition->field] ?? throw new \LogicException('A checked condition names a field of its list.');
        $op = $condition->op;
        $value = $condition->value;
        $p = ':'.$name;
        if ('is_empty' === $op) {
            return [FieldType::Text === $field->type ? \sprintf('(%1$s IS NULL OR %1$s = \'\')', $column) : $column.' IS NULL', []];
        }
        if ('is_not_empty' === $op) {
            return [$column.' IS NOT NULL', []];
        }

        return match ($field->type) {
            FieldType::Enum, FieldType::Reference => match ($op) {
                'is' => [$column.' = '.$p, [$name => [$value, null]]],
                'is_not' => [\sprintf('(%1$s <> %2$s OR %1$s IS NULL)', $column, $p), [$name => [$value, null]]],
                'is_any_of' => [$column.' IN ('.$p.')', [$name => [$value, ArrayParameterType::STRING]]],
                'is_none_of' => [\sprintf('(%1$s NOT IN (%2$s) OR %1$s IS NULL)', $column, $p), [$name => [$value, ArrayParameterType::STRING]]],
                default => throw new \LogicException('A checked operator compiles.'),
            },
            FieldType::Boolean => [$column.' = '.$p, [$name => [$value, Types::BOOLEAN]]],
            FieldType::Text => $this->text($op, $column, \is_string($value) ? $value : '', $name),
            FieldType::Number => $this->number($op, $column, $this->scaled($value, $this->scales[$field->key] ?? 1), $name),
            FieldType::Date => $this->date($op, $column, $value, $today(), $name),
        };
    }

    /**
     * @return array{string, Parameters}
     */
    private function text(string $op, string $column, string $value, string $name): array
    {
        $p = ':'.$name;
        $like = '%'.strtr(mb_strtolower($value), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return match ($op) {
            'contains' => ['LOWER('.$column.') LIKE '.$p.' ESCAPE \'!\'', [$name => [$like, null]]],
            'does_not_contain' => [\sprintf('(%1$s IS NULL OR LOWER(%1$s) NOT LIKE %2$s ESCAPE \'!\')', $column, $p), [$name => [$like, null]]],
            'is' => [$column.' = '.$p, [$name => [$value, null]]],
            default => throw new \LogicException('A checked operator compiles.'),
        };
    }

    private function scaled(mixed $value, int $scale): mixed
    {
        if (1 === $scale) {
            return $value;
        }
        if (\is_array($value)) {
            return array_map(fn (mixed $end): mixed => $this->scaled($end, $scale), $value);
        }

        return \is_int($value) || \is_float($value) ? (int) round($value * $scale) : $value;
    }

    /**
     * @return array{string, Parameters}
     */
    private function number(string $op, string $column, mixed $value, string $name): array
    {
        $p = ':'.$name;
        if ('between' === $op) {
            \assert(\is_array($value));

            return [\sprintf('%1$s >= %2$s_from AND %1$s <= %2$s_to', $column, $p), [$name.'_from' => [$value['from'], null], $name.'_to' => [$value['to'], null]]];
        }
        $sign = ['eq' => '=', 'ne' => '<>', 'lt' => '<', 'le' => '<=', 'gt' => '>', 'ge' => '>='][$op] ?? throw new \LogicException('A checked operator compiles.');

        return [$column.' '.$sign.' '.$p, [$name => [$value, null]]];
    }

    /**
     * A period with "is" covers the whole period; "before" is before it starts and
     * "after" after it ends (spec D5). Every bound is a day, from-inclusive.
     *
     * @return array{string, Parameters}
     */
    private function date(string $op, string $column, mixed $value, \DateTimeImmutable $today, string $name): array
    {
        if ('between' === $op) {
            \assert(\is_array($value));
            [$start, $end] = [DateRange::of($value['from'], $today)->start, DateRange::of($value['to'], $today)->end];
        } else {
            $range = DateRange::of($value, $today);
            [$start, $end] = [$range->start, $range->end];
        }
        $from = [$name.'_start' => [$start, Types::DATE_IMMUTABLE]];
        $to = [$name.'_end' => [$end, Types::DATE_IMMUTABLE]];
        [$s, $e] = [':'.$name.'_start', ':'.$name.'_end'];

        return match ($op) {
            'is', 'between' => [\sprintf('%1$s >= %2$s AND %1$s < %3$s', $column, $s, $e), $from + $to],
            'before' => [$column.' < '.$s, $from],
            'on_or_after' => [$column.' >= '.$s, $from],
            'after' => [$column.' >= '.$e, $to],
            'on_or_before' => [$column.' < '.$e, $to],
            default => throw new \LogicException('A checked operator compiles.'),
        };
    }
}
