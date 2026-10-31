<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** What an import does with the records the source holds. */
enum ImportMode: string
{
    /** Creates records that do not exist yet and leaves the others alone. */
    case AddMissing = 'add_missing';
    /** Creates missing records and updates the ones found. */
    case Update = 'update';
    /** Makes the target match the source; records missing from it are archived. */
    case FullReplace = 'full_replace';
}
