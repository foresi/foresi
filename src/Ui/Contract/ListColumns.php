<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that declares the columns of a list, such as "invoicing.issued", or adds columns to a
 * list of another module. The list's module renders its rows; a contributed column renders its
 * cell from the row it is given, so the list never links to the contributing module.
 */
#[AutoconfigureTag(self::TAG)]
interface ListColumns
{
    public const string TAG = 'app.list_columns';

    /**
     * @return iterable<Column> nothing for a list the provider does not fill
     */
    public function columns(string $list): iterable;
}
