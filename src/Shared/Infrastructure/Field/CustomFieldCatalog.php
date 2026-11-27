<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Field;

use Foresi\Shared\Application\Field\FieldRecords;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Application\Field\VisibleFields;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListFieldCatalog;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;

/**
 * The filter fields of the custom fields of a record's lists (ADR 0045): each
 * filterable field, read in its extension's row, so saved views, quick filters and
 * the filter of the search tools take it. A reference compares the id it holds and
 * names its source (FieldReferenceNames::CONTACT_SOURCE, USER_SOURCE), which the
 * filter sheet picks from and names by (FilterSheet).
 */
final readonly class CustomFieldCatalog implements ListFieldCatalog
{
    public function __construct(private FieldRecords $records, private Fields $fields, private VisibleFields $visible)
    {
    }

    public function fields(string $listKey): array
    {
        $list = $this->records->ofList($listKey);
        if (null === $list) {
            return [];
        }
        [$record, $alias] = $list;
        $fields = [];
        foreach ($this->visible->definitions($record->record()) as $definition) {
            if (!$definition->filterable) {
                continue;
            }
            $expression = $this->fields->expression($record->record(), $definition->key, $alias);
            $options = array_map(strval(...), array_keys($definition->options));
            $fields[] = new Field(
                $definition->key,
                match ($definition->kind) {
                    FieldKind::Text, FieldKind::LongText, FieldKind::Url => FieldType::Text,
                    FieldKind::Number => FieldType::Number,
                    FieldKind::Date, FieldKind::DateTime => FieldType::Date,
                    FieldKind::Boolean => FieldType::Boolean,
                    FieldKind::Select => FieldType::Enum,
                    FieldKind::MultiSelect => FieldType::MultiEnum,
                    FieldKind::Contact, FieldKind::User => FieldType::Reference,
                },
                $definition->label,
                options: $options,
                optionLabels: [] === $options ? [] : array_combine($options, array_values($definition->options)),
                source: ReferenceNames::sourceOf($definition->kind),
                expression: $expression->column,
                within: $expression->within(),
            );
        }

        return $fields;
    }
}
