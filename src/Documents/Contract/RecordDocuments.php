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
 *
 * A module may also open "New document" itself, on the route
 * DocumentController::NEW_ROUTE (/documents/new), with link_kind and link_id
 * for the record and links[]=kind:id, repeated, for further records, such as
 * a deal and its client. A malformed or repeated link is left out.
 */
final class RecordDocuments
{
    public const string ROUTE = 'documents_for_record';

    /** The record kind of a document, for links from other modules. */
    public const string KIND = 'document';
}
