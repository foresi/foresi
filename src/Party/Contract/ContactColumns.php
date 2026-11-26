<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

use Foresi\Ui\Contract\Attribute;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Columns of the contact list (Contacts::LIST) that another module fills from
 * a row it keeps for each contact. The address book shows, filters and sorts
 * them as its own: each attribute's value and cell read the contact's id, its
 * sort and filter are DQL over the joined row, and its "within" names that row
 * for the filters.
 */
#[AutoconfigureTag(self::TAG)]
interface ContactColumns
{
    public const string TAG = 'foresi.party.contact_columns';

    /**
     * @return iterable<Attribute>
     */
    public function attributes(): iterable;

    /**
     * The DQL "LEFT JOIN <entity> <alias> WITH <condition>" of the row that the
     * sorts read, joined to the contact Contacts::ALIAS.
     */
    public function join(): string;

    /**
     * Reads the rows of a page of contacts once, before its cells are read.
     *
     * @param list<string> $contactIds
     */
    public function load(array $contactIds): void;
}
