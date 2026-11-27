<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

/**
 * What the contact list sorts by beyond the name: the columns of the address
 * book, which the contacts module shows and sorts (its ContactSource), and the
 * rows other modules keep for each contact. PartyQuery asks it for the DQL of
 * a sort and has it read the rows of a page once. Without the address book
 * there is no list to sort, and a contact sorts by its name.
 */
interface ContactSorts
{
    /**
     * The DQL expression over the contact "p" that each sortable column sorts
     * by, by its key and by its aliases.
     *
     * @return array<string, string>
     */
    public function sorts(): array;

    /**
     * The join a sort by the column needs, such as the row of the module that
     * adds it; nothing for a column of the contact itself.
     */
    public function join(string $key): string;

    /**
     * Reads the rows of a page of contacts once, before its cells are read.
     *
     * @param list<string> $ids
     */
    public function load(array $ids): void;
}
