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

/**
 * The filter fields of a list, one per filterable attribute of its data source (spec DS1, D4),
 * in the order of its columns: a text, an e-mail, a phone, a web address and a country compare
 * as text; an amount as a number in whole units, its expression holding minor ones; a date and
 * a time as a day. A reference is left to its module, which knows the picker it needs.
 */
final readonly class AttributeFieldCatalog implements ListFieldCatalog
{
    /** What an amount in whole units is multiplied by to compare with its minor units. */
    private const int MINOR_UNITS = 100;

    public function __construct(private ListColumnRegistry $registry)
    {
    }

    public function fields(string $listKey): array
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
