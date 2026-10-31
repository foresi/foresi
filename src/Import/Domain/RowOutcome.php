<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** What happens, or happened, to one source row. */
enum RowOutcome: string
{
    case Create = 'create';
    case Update = 'update';
    case Restore = 'restore';
    case Skip = 'skip';
    case Archive = 'archive';
    case Error = 'error';
    /** The undo put the record back as it was. */
    case Reverted = 'reverted';
    /** The undo left the record alone because someone changed it after the import. */
    case ChangedAfterImport = 'changed_after_import';

    /**
     * What a row with a usable key becomes, from the existing records its key matches.
     * Several matches are an error ("ambiguous"); an archived match is restored by the
     * modes that write, and left alone by AddMissing.
     */
    public static function forMatches(ImportMode $mode, int $matches, bool $archived): self
    {
        if ($matches < 0) {
            throw new \InvalidArgumentException('The number of matches must not be negative.');
        }

        return match (true) {
            0 === $matches => self::Create,
            $matches > 1 => self::Error,
            ImportMode::AddMissing === $mode => self::Skip,
            $archived => self::Restore,
            default => self::Update,
        };
    }

    /** Whether the run wrote a record for the row, so the undo has something to put back. */
    public function wroteRecord(): bool
    {
        return \in_array($this, [self::Create, self::Update, self::Restore], true);
    }
}
