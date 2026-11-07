<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

/** What an import did with one iDoklad record. */
enum ImportOutcome: string
{
    case Created = 'created';
    /** Linked to a record the user already had in Foresi. */
    case Matched = 'matched';
    /** An earlier run linked it. */
    case AlreadyLinked = 'already_linked';
    /** Not imported: the reasons say what is missing or wrong. */
    case Refused = 'refused';
    /** Linked earlier, but iDoklad and Foresi now disagree; the values say how. */
    case Mismatch = 'mismatch';
    /** Left out on purpose, as nothing in Foresi takes it: the reasons say why. */
    case Skipped = 'skipped';
}
