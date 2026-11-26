<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Column;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Field\FieldRecords;
use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * A column of each custom field in every list of its record (ADR 0045), hidden until
 * a person adds it. The value is read through the registry, which the list's query
 * filled for its page; a reference shows the name of the record it points to. A field
 * not open to guests is no column for them. The filter fields come from
 * CustomFieldCatalog; a sortable field sorts a list whose query sorts by columns.
 */
final readonly class CustomFieldColumns implements DataSource
{
    /** After the list's own columns. */
    private const int POSITION = 1000;

    /**
     * @param iterable<FieldReferenceNames> $names
     */
    public function __construct(
        private FieldRecords $records,
        private Fields $fields,
        private ActorRole $actor,
        #[AutowireIterator(FieldReferenceNames::TAG)]
        private iterable $names = [],
    ) {
    }

    public function attributes(string $list): iterable
    {
        $found = $this->records->ofList($list);
        if (null === $found) {
            return [];
        }
        [$record, $alias] = $found;
        $sorted = \in_array($list, $record->sorted(), true);
        $attributes = [];
        foreach ($this->fields->definitions($record->record()) as $index => $definition) {
            $value = fn (mixed $row): mixed => $this->fields->value($definition->record, $record->id($row), $definition->key);
            $attributes[] = new Attribute(
                $definition->key,
                $definition->label,
                $this->type($definition->kind),
                \in_array($definition->kind, [FieldKind::Contact, FieldKind::User], true) ? fn (mixed $row): ?string => $this->name($definition, $value($row)) : $value,
                sort: $sorted && $definition->sortable ? $this->fields->expression($record->record(), $definition->key, $alias)->column : null,
                defaultPosition: self::POSITION + $index,
                options: $definition->options,
                truncate: FieldKind::LongText === $definition->kind,
                available: $definition->guests ? null : $this->actor->isStaff(...),
            );
        }

        return $attributes;
    }

    private function type(FieldKind $kind): AttributeType
    {
        return match ($kind) {
            FieldKind::Text, FieldKind::LongText => AttributeType::Text,
            FieldKind::Number => AttributeType::Number,
            FieldKind::Date => AttributeType::Date,
            FieldKind::DateTime => AttributeType::DateTime,
            FieldKind::Boolean => AttributeType::Boolean,
            FieldKind::Url => AttributeType::Url,
            FieldKind::Select => AttributeType::Enum,
            FieldKind::MultiSelect => AttributeType::MultiEnum,
            FieldKind::Contact, FieldKind::User => AttributeType::Reference,
        };
    }

    private function name(FieldDefinition $definition, mixed $id): ?string
    {
        if (!\is_string($id)) {
            return null;
        }

        return $this->namesOf($definition->kind)?->names([$id])[$id] ?? $id;
    }

    private function namesOf(FieldKind $kind): ?FieldReferenceNames
    {
        foreach ($this->names as $names) {
            if ($names->kind() === $kind) {
                return $names;
            }
        }

        return null;
    }
}
