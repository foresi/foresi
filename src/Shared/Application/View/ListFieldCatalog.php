<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The fields of a list derived from its data source (spec DS1, D4): every attribute that has a
 * DQL expression, typed by its attribute's type. The screens own the data sources, so the
 * presentation layer answers this through the tag, as ListColumnCatalog answers for columns.
 */
#[AutoconfigureTag(self::TAG)]
interface ListFieldCatalog
{
    public const string TAG = 'app.list_field_catalog';

    /**
     * @return list<Field> in the order of the list's columns
     */
    public function fields(string $listKey): array;
}
