<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract;

/**
 * The documents linked to one record, as a page fragment that another module
 * shows inside its own screen, such as a tab of a contact. Link to it with
 * the route ROUTE and the parameters "kind" and "id"; the fragment lists the
 * documents and offers "New document" with the link filled in.
 */
final class RecordDocuments
{
    public const string ROUTE = 'documents_for_record';

    /** The record kind of a document, for links from other modules. */
    public const string KIND = 'document';
}
