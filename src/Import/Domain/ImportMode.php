<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Domain;

use Foresi\Import\Contract\RowOutcome;

/** What an import does with the records the source holds. */
enum ImportMode: string
{
    /** Creates records that do not exist yet and leaves the others alone. */
    case AddMissing = 'add_missing';
    /** Creates missing records and updates the ones found. */
    case Update = 'update';
    /** Makes the target match the source; records missing from it are archived. */
    case FullReplace = 'full_replace';

    /**
     * What a row with a usable key becomes, from the existing records its key matches.
     * Several matches are an error ("ambiguous"); an archived match is restored by the
     * modes that write, and left alone by AddMissing.
     */
    public function outcomeFor(int $matches, bool $archived): RowOutcome
    {
        if ($matches < 0) {
            throw new \InvalidArgumentException('The number of matches must not be negative.');
        }

        return match (true) {
            0 === $matches => RowOutcome::Create,
            $matches > 1 => RowOutcome::Error,
            self::AddMissing === $this => RowOutcome::Skip,
            $archived => RowOutcome::Restore,
            default => RowOutcome::Update,
        };
    }
}
