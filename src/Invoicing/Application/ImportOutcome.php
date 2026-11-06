<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

enum ImportOutcome: string
{
    case Imported = 'imported';
    /** The source and id were imported before; nothing changed. */
    case AlreadyImported = 'already_imported';
    /** Foresi comes to other totals than the source; nothing was stored. */
    case Mismatch = 'mismatch';
    /** A rule refused the document; nothing was stored. */
    case Refused = 'refused';
}
