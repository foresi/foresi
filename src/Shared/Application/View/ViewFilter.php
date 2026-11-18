<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * What a view keeps of a list: conditions that all hold (spec D4, v1 is AND only;
 * OR within one field goes through is_any_of). The stored form is
 * {match: "all", conditions: [{field, op, value}]}, which leaves room for groups.
 */
final readonly class ViewFilter
{
    public const int MAX_CONDITIONS = 20;
    public const string MATCH_ALL = 'all';

    /**
     * @param list<Condition> $conditions
     */
    public function __construct(public array $conditions = [])
    {
        if (\count($conditions) > self::MAX_CONDITIONS) {
            throw new InvalidFilter('conditions', \sprintf('A view holds at most %d conditions.', self::MAX_CONDITIONS));
        }
    }

    public static function all(Condition ...$conditions): self
    {
        // Named arguments would key them by name.
        return new self(array_values($conditions));
    }

    /**
     * The filter of a stored or posted form, checked against the fields of its list.
     *
     * @throws InvalidFilter
     */
    public static function parse(mixed $data, ListDefinition $list): self
    {
        if (!\is_array($data)) {
            throw new InvalidFilter('filters', 'A filter is an object of match and conditions.');
        }
        if (self::MATCH_ALL !== ($data['match'] ?? self::MATCH_ALL)) {
            throw new InvalidFilter('match', 'Only "all" is supported.');
        }
        $unknown = array_diff(array_keys($data), ['match', 'conditions']);
        if ([] !== $unknown) {
            throw new InvalidFilter((string) reset($unknown), 'Unknown key.');
        }
        $items = $data['conditions'] ?? [];
        if (!\is_array($items) || !array_is_list($items)) {
            throw new InvalidFilter('conditions', 'The conditions are a list.');
        }
        $conditions = [];
        foreach ($items as $index => $item) {
            $path = \sprintf('conditions[%d]', $index);
            if (!\is_array($item)) {
                throw new InvalidFilter($path, 'A condition is an object.');
            }
            if (!\is_string($item['field'] ?? null) || !\is_string($item['op'] ?? null)) {
                throw new InvalidFilter($path, 'A condition has a field and an op.');
            }
            $conditions[] = new Condition($item['field'], $item['op'], $item['value'] ?? null);
        }
        $filter = new self($conditions);
        $filter->check($list);

        return $filter;
    }

    /**
     * @throws InvalidFilter
     */
    public function check(ListDefinition $list): void
    {
        foreach ($this->conditions as $index => $condition) {
            $path = \sprintf('conditions[%d]', $index);
            $field = $list->fields[$condition->field] ?? throw new InvalidFilter($path.'.field', \sprintf('The list "%s" has no field "%s".', $list->key, $condition->field));
            if (!\in_array($condition->op, $field->operators, true)) {
                throw new InvalidFilter($path.'.op', \sprintf('The field "%s" takes %s.', $field->key, implode(', ', $field->operators)));
            }
            $this->checkValue($field, $condition, $path.'.value');
        }
    }

    /**
     * @return array{match: string, conditions: list<array{field: string, op: string, value?: mixed}>}
     */
    public function toArray(): array
    {
        return ['match' => self::MATCH_ALL, 'conditions' => array_map(static fn (Condition $condition): array => $condition->toArray(), $this->conditions)];
    }

    /**
     * The conditions on a field.
     *
     * @return list<Condition>
     */
    public function on(string $field): array
    {
        return array_values(array_filter($this->conditions, static fn (Condition $condition): bool => $condition->field === $field));
    }

    public function with(Condition $condition): self
    {
        return new self([...$this->conditions, $condition]);
    }

    private function checkValue(Field $field, Condition $condition, string $path): void
    {
        $value = $condition->value;
        if (\in_array($condition->op, ['is_empty', 'is_not_empty'], true)) {
            if (null !== $value) {
                throw new InvalidFilter($path, 'An emptiness check takes no value.');
            }

            return;
        }
        $between = 'between' === $condition->op;
        $number = static fn (mixed $part, string $at) => self::checkScalar(\is_int($part) || \is_float($part), $at, 'a number');
        $date = DateRange::check(...);
        switch ($field->type) {
            case FieldType::Enum:
                $this->checkEnum($field, $condition, $path);
                break;
            case FieldType::Reference:
                if ('is_any_of' === $condition->op) {
                    $this->checkList($value, $path, \is_string(...), 'ids');
                } else {
                    self::checkScalar(\is_string($value) && '' !== $value, $path, 'an id');
                }
                break;
            case FieldType::Text:
                self::checkScalar(\is_string($value) && '' !== $value, $path, 'a text');
                break;
            case FieldType::Number:
                $this->checkOne($between, $value, $path, $number);
                break;
            case FieldType::Date:
                $this->checkOne($between, $value, $path, $date);
                break;
            case FieldType::Boolean:
                self::checkScalar(\is_bool($value), $path, 'true or false');
                break;
        }
    }

    private function checkEnum(Field $field, Condition $condition, string $path): void
    {
        $known = static fn (mixed $value): bool => \is_string($value) && \in_array($value, $field->options, true);
        if (\in_array($condition->op, ['is_any_of', 'is_none_of'], true)) {
            $this->checkList($condition->value, $path, $known, 'values of '.implode(', ', $field->options));

            return;
        }
        self::checkScalar($known($condition->value), $path, 'one of '.implode(', ', $field->options));
    }

    /**
     * @param callable(mixed): bool $each
     */
    private function checkList(mixed $value, string $path, callable $each, string $what): void
    {
        if (!\is_array($value) || [] === $value || !array_is_list($value) || \count($value) !== \count(array_filter($value, $each))) {
            throw new InvalidFilter($path, \sprintf('Expected a list of %s.', $what));
        }
    }

    /**
     * One value, or the two ends of a range.
     *
     * @param callable(mixed, string): void $each
     */
    private function checkOne(bool $between, mixed $value, string $path, callable $each): void
    {
        if (!$between) {
            $each($value, $path);

            return;
        }
        if (!\is_array($value) || !\array_key_exists('from', $value) || !\array_key_exists('to', $value) || 2 !== \count($value)) {
            throw new InvalidFilter($path, 'A range is {from, to}.');
        }
        $each($value['from'], $path.'.from');
        $each($value['to'], $path.'.to');
    }

    private static function checkScalar(bool $valid, string $path, string $what): void
    {
        if (!$valid) {
            throw new InvalidFilter($path, \sprintf('Expected %s.', $what));
        }
    }
}
