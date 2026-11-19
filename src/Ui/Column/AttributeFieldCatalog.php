<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Column;

use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListFieldCatalog;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\Relation;
use Foresi\Ui\Contract\RelationSource;
use Foresi\Ui\Contract\RelationTarget;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The filter fields of a list, one per filterable attribute of its data source (spec DS1, D4),
 * in the order of its columns: a text, an e-mail, a phone, a web address and a country compare
 * as text; an amount as a number in whole units, its expression holding minor ones; a date and
 * a time as a day. A reference is left to its module, which knows the picker it needs.
 *
 * Then the fields of the list's relations (spec DS6): of a relation to one record, every field
 * of its target's data source, keyed and labelled after the relation ("customer.city",
 * "Customer: City") and read in the target's row, and in the target's own related row where
 * the attribute reads in one; of a relation to many, whether the row has related records, and
 * the name of one when the relation gives it. One hop: a target's relations are not followed.
 */
final readonly class AttributeFieldCatalog implements ListFieldCatalog
{
    /** What an amount in whole units is multiplied by to compare with its minor units. */
    private const int MINOR_UNITS = 100;

    /**
     * @param iterable<RelationSource> $relations
     * @param iterable<RelationTarget> $targets
     */
    public function __construct(
        private ListColumnRegistry $registry,
        #[AutowireIterator(RelationSource::TAG)]
        private iterable $relations = [],
        #[AutowireIterator(RelationTarget::ROOT_TAG)]
        private iterable $targets = [],
    ) {
    }

    public function fields(string $listKey): array
    {
        $fields = $this->own($listKey);
        foreach ($this->relations as $source) {
            foreach ($source->relations($listKey) as $relation) {
                $fields = [...$fields, ...$this->related($relation)];
            }
        }

        return $fields;
    }

    /**
     * @return list<Field>
     */
    private function own(string $listKey): array
    {
        $fields = [];
        foreach ($this->registry->columns($listKey) as $column) {
            $field = $this->field($column);
            if ($field instanceof Field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return list<Field>
     */
    private function related(Relation $relation): array
    {
        if ($relation->many()) {
            $fields = [new Field($relation->key, FieldType::Relation, $relation->label, within: (string) $relation->within)];
            if (null !== $relation->name) {
                $fields[] = new Field($relation->key.'.name', FieldType::Text, $relation->nameLabel, ['contains', 'does_not_contain', 'is', 'is_not'], expression: $relation->name, within: (string) $relation->within, group: $relation->label);
            }

            return $fields;
        }
        $target = (string) $relation->target;
        $root = $this->root($target);
        if (null === $root) {
            return [];
        }
        $within = $root[0].' '.$root[1].' WHERE '.$root[1].' = '.$relation->on;

        return array_map(static fn (Field $field): Field => new Field(
            $relation->key.'.'.$field->key,
            $field->type,
            $field->label,
            $field->operators,
            $field->options,
            $field->optionLabels,
            $field->source,
            expression: $field->expression,
            within: self::joined($within, $field->within),
            scale: $field->scale,
            group: $relation->label,
        ), $this->own($target));
    }

    /**
     * The entity and the alias of the row a list's attributes read, given by its data source.
     *
     * @return array{string, string}|null
     */
    private function root(string $list): ?array
    {
        foreach ($this->targets as $target) {
            $root = $target->root($list);
            if (null !== $root) {
                if (1 !== preg_match('/^\s*(\S+)\s+([A-Za-z_]\w*)\s*$/', $root, $match)) {
                    throw new \LogicException(\sprintf('The row of list "%s" is "<entity> <alias>".', $list));
                }

                return [$match[1], $match[2]];
            }
        }

        return null;
    }

    /**
     * The rows a field of a related record reads in: the relation's, and the target attribute's
     * own related row after it, such as a contact's billing address.
     */
    private static function joined(string $relation, ?string $attribute): string
    {
        if (null === $attribute) {
            return $relation;
        }
        $split = '/^(.+?)\s+WHERE\s+(.+)$/is';
        if (1 !== preg_match($split, $relation, $one) || 1 !== preg_match($split, $attribute, $other)) {
            throw new \LogicException('A relation and an attribute read in "<entity> <alias> WHERE <correlation>".');
        }

        return $one[1].', '.$other[1].' WHERE ('.$one[2].') AND ('.$other[2].')';
    }

    private function field(Column $column): ?Field
    {
        $attribute = $column->attribute;
        $expression = $attribute->filterExpression();
        if (null === $expression) {
            return null;
        }
        $type = match ($attribute->type) {
            AttributeType::Text, AttributeType::Email, AttributeType::Phone, AttributeType::Url, AttributeType::Country => FieldType::Text,
            AttributeType::Number, AttributeType::Money => FieldType::Number,
            AttributeType::Date, AttributeType::DateTime => FieldType::Date,
            AttributeType::Enum => FieldType::Enum,
            AttributeType::MultiEnum => FieldType::MultiEnum,
            AttributeType::Boolean => FieldType::Boolean,
            AttributeType::Reference => null,
        };
        if (null === $type) {
            return null;
        }

        return new Field(
            $attribute->key,
            $type,
            $attribute->label,
            options: array_map(strval(...), array_keys($attribute->options)),
            optionLabels: array_combine(array_map(strval(...), array_keys($attribute->options)), $attribute->options),
            aliases: $attribute->aliases,
            expression: $expression,
            within: $attribute->within,
            scale: AttributeType::Money === $attribute->type ? self::MINOR_UNITS : 1,
        );
    }
}
