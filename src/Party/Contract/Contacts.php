<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * The contact list as other modules relate to it (spec DS6): its list key, and the alias the
 * expressions of its data source read a contact as. A relation to a contact names the list and
 * the contact a record points to, and the list's data source reads the contact in; a relation of
 * a contact's to many, such as its invoices, correlates its rows with ALIAS, the contact of the
 * list's row.
 */
final class Contacts
{
    public const string LIST = 'party.contacts';

    /** The contact in the expressions of the contact list's data source and in its query. */
    public const string ALIAS = 'p';
}
