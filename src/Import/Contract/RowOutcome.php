<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Contract;

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

    /** Whether the run wrote a record for the row, so the undo has something to put back. */
    public function wroteRecord(): bool
    {
        return \in_array($this, [self::Create, self::Update, self::Restore], true);
    }
}
