<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * What a module builds its list definition from (spec D3): the fields every catalogue derives
 * from the list's data source, and the module's own fields of what no attribute shows, such as
 * whether a contact is archived. A module's own field wins over a derived one of its key, so a
 * field a module compiles itself keeps its type and its stored views.
 */
final readonly class ListFields
{
    /**
     * @param iterable<ListFieldCatalog> $catalogs
     */
    public function __construct(
        #[AutowireIterator(ListFieldCatalog::TAG)]
        private iterable $catalogs,
    ) {
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
