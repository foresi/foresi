<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that declares the data source of a list, such as "invoicing.invoices": every attribute
 * a person can show of its rows, which of them show by default, and how its query sorts by them
 * (spec DS1). Another module may add attributes to a list it does not own; the list's module
 * passes its rows, and a contributed attribute reads its value from the row it is given, so the
 * list never links to the contributing module.
 */
#[AutoconfigureTag(self::TAG)]
interface DataSource
{
    public const string TAG = 'app.data_source';

    /**
     * @return iterable<Attribute> nothing for a list the source does not fill
     */
    public function attributes(string $list): iterable;
}
