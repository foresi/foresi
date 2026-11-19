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
use Foresi\Shared\Application\View\Field;
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
 * A field of the list's data source brings its expression (Field::$expression), so
 * the module maps only what it stores outside its catalogue. A value of a related row,
 * such as an e-mail of a contact, is read in a correlated subquery: the condition holds
 * when one related row matches, and its negation when none does. A set of an enum's
 * values (multi_enum) is a JSON list column, matched as text.
 *
 * Each compile names its parameters from its prefix, so one count query can hold
 * the predicates of many views side by side (D9).
 *
 * @phpstan-type Parameters array<string, array{mixed, string|ArrayParameterType|null}>
 * @phpstan-type Derived \Closure(Condition, string): array{string, Parameters}
 */
final readonly class DqlConditions
{
    /** The negation of each positive operator, for a value of a related row. */
    private const array NEGATIONS = ['does_not_contain' => 'contains', 'is_not' => 'is', 'is_empty' => 'is_not_empty', 'ne' => 'eq', 'is_none_of' => 'is_any_of'];

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
            $field = $this->list->fields[$condition->field] ?? null;
            $column = $this->columns[$condition->field] ?? $field?->expression;
            [$dql, $bound] = null === $column || !$field instanceof Field ? ($this->derived)($condition, $name) : $this->read($condition, $field, $column, $today, $name);
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
     * A field of the row, or of a related row: a negation of a related value holds when no
     * related row has the value, so it is the subquery of the positive condition, negated.
     *
     * @param \Closure(): \DateTimeImmutable $today
     *
     * @return array{string, Parameters}
     */
    private function read(Condition $condition, Field $field, string $column, \Closure $today, string $name): array
    {
        if (null === $field->within) {
            return $this->stored($condition, $field, $column, $today, $name);
        }
        // DQL names every alias once in a query, so each subquery renames its own.
        if (1 !== preg_match('/^\s*(\S+)\s+([A-Za-z_]\w*)\s+WHERE\s/i', $field->within, $match)) {
            throw new \LogicException(\sprintf('The field "%s" reads in "<entity> <alias> WHERE <correlation>".', $field->key));
        }
        $alias = '/\b'.$match[2].'\./';
        $own = $match[2].'_'.$name;
        $within = $match[1].' '.$own.' '.preg_replace($alias, $own.'.', substr($field->within, \strlen($match[0]) - 6));
        $column = (string) preg_replace($alias, $own.'.', $column);
        $negated = self::NEGATIONS[$condition->op] ?? null;
        [$dql, $bound] = $this->stored(null === $negated ? $condition : new Condition($condition->field, $negated, $condition->value), $field, $column, $today, $name);

        return [(null === $negated ? '' : 'NOT ').'EXISTS (SELECT 1 FROM '.$within.' AND '.$dql.')', $bound];
    }

    /**
     * @param \Closure(): \DateTimeImmutable $today
     *
     * @return array{string, Parameters}
     */
    private function stored(Condition $condition, Field $field, string $column, \Closure $today, string $name): array
    {
        $op = $condition->op;
        $value = $condition->value;
        $p = ':'.$name;
        $type = $field->type;
        if (FieldType::MultiEnum === $type) {
            return $this->set($op, $column, \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [], $name);
        }
        if ('is_empty' === $op) {
            return [FieldType::Text === $field->type ? \sprintf('(%1$s IS NULL OR %1$s = \'\')', $column) : $column.' IS NULL', []];
        }
        if ('is_not_empty' === $op) {
            return [FieldType::Text === $field->type ? \sprintf('(%1$s IS NOT NULL AND %1$s <> \'\')', $column) : $column.' IS NOT NULL', []];
        }

        return match ($type) {
            FieldType::Enum, FieldType::Reference => match ($op) {
                'is' => [$column.' = '.$p, [$name => [$value, null]]],
                'is_not' => [\sprintf('(%1$s <> %2$s OR %1$s IS NULL)', $column, $p), [$name => [$value, null]]],
                'is_any_of' => [$column.' IN ('.$p.')', [$name => [$value, ArrayParameterType::STRING]]],
                'is_none_of' => [\sprintf('(%1$s NOT IN (%2$s) OR %1$s IS NULL)', $column, $p), [$name => [$value, ArrayParameterType::STRING]]],
                default => throw new \LogicException('A checked operator compiles.'),
            },
            FieldType::Boolean => [$column.' = '.$p, [$name => [$value, Types::BOOLEAN]]],
            FieldType::Text => $this->text($op, $column, \is_string($value) ? $value : '', $name),
            FieldType::Number => $this->number($op, $column, $this->scaled($value, $this->scales[$field->key] ?? $field->scale), $name),
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
            'is_not' => [\sprintf('(%1$s IS NULL OR %1$s <> %2$s)', $column, $p), [$name => [$value, null]]],
            default => throw new \LogicException('A checked operator compiles.'),
        };
    }

    /**
     * A set of an enum's values in a JSON list column: each value is matched as its quoted
     * JSON string in the column's text, which reads the same on every database. A blank set
     * (null or []) has none of any values (Airtable).
     *
     * @param list<string> $values
     *
     * @return array{string, Parameters}
     */
    private function set(string $op, string $column, array $values, string $name): array
    {
        $text = 'CAST_TEXT('.$column.')';
        if ('is_empty' === $op || 'is_not_empty' === $op) {
            $empty = \sprintf('(%1$s IS NULL OR %2$s = \'[]\')', $column, $text);

            return ['is_empty' === $op ? $empty : 'NOT '.$empty, []];
        }
        $parts = [];
        $parameters = [];
        foreach ($values as $index => $value) {
            $parts[] = $text.' LIKE :'.$name.'_'.$index.' ESCAPE \'!\'';
            $parameters[$name.'_'.$index] = ['%'.strtr(json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), ['!' => '!!', '%' => '!%', '_' => '!_']).'%', null];
        }

        return match ($op) {
            'has_any_of' => ['('.implode(' OR ', $parts).')', $parameters],
            'has_all_of' => ['('.implode(' AND ', $parts).')', $parameters],
            'has_none_of' => [\sprintf('(%1$s IS NULL OR NOT (%2$s))', $column, implode(' OR ', $parts)), $parameters],
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
