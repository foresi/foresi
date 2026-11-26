<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that declares relations of a list's rows (spec DS6): its own list's, such as an
 * invoice's customer, or another module's, such as the invoices of a contact, which the
 * contributing module reads in, so the list's module never links to it.
 */
#[AutoconfigureTag(self::TAG)]
interface RelationSource
{
    public const string TAG = 'foresi.ui.relation_source';

    /**
     * @return iterable<Relation> nothing for a list the source does not relate
     */
    public function relations(string $list): iterable;
}
