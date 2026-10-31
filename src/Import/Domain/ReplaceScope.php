<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** Which records a full replace may archive. */
enum ReplaceScope: string
{
    /** Only the records an import with the same profile created. */
    case Profile = 'profile';
    /** Every record of the target. */
    case All = 'all';
}
