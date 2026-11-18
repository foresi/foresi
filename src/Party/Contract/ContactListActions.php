<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * The actions other modules add to the address book, such as import and
 * export. A provider answers the Ui slots with items that carry a template.
 */
final class ContactListActions
{
    /**
     * Items of the overflow menu in the page header. Context: "firstRun"
     * (bool, the book is empty) and "query" (the list as it is filtered:
     * "view", "q", and "import" with "outcome" when narrowed to an import).
     */
    public const string HEADER = 'party.contact_list.header';

    /** Buttons for the selected rows, which post the form of the list. Context: "view". */
    public const string BULK = 'party.contact_list.bulk';
}
