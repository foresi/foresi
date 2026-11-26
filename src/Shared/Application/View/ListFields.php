<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Application\Field\VisibleFields;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * What a module builds its list definition from (spec D3): the fields every catalogue derives
 * from the list's data source, and the module's own fields of what no attribute shows, such as
 * whether a contact is archived. A module's own field wins over a derived one of its key, so a
 * field a module compiles itself keeps its type and its stored views.
 *
 * The custom fields are those the current actor sees (ADR 0045), so a module keeps the list
 * it builds by audience(): a guest's list and a token's list are not the staff's.
 */
final readonly class ListFields
{
    /**
     * @param iterable<ListFieldCatalog> $catalogs
     */
    public function __construct(
        #[AutowireIterator(ListFieldCatalog::TAG)]
        private iterable $catalogs,
        private ?VisibleFields $visible = null,
    ) {
    }

    /**
     * The key of what the current actor sees, to keep a list built of these fields by.
     */
    public function audience(): string
    {
        return $this->visible?->audience() ?? '';
    }

    /**
     * @param list<Field> $own the module's fields, first
     *
     * @return list<Field>
     */
    public function of(string $listKey, array $own = []): array
    {
        $fields = [];
        foreach ($own as $field) {
            $fields[$field->key] = $field;
        }
        foreach ($this->catalogs as $catalog) {
            foreach ($catalog->fields($listKey) as $field) {
                $fields[$field->key] ??= $field;
            }
        }

        return array_values($fields);
    }
}
