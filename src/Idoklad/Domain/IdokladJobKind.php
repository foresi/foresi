<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

/** What a background job of the move from iDoklad does. */
enum IdokladJobKind: string
{
    /** Copies the whole account, then its PDFs. */
    case Pull = 'pull';
    /** Copies what changed since the last pull that succeeded, then the PDFs still missing. */
    case Changes = 'changes';
    case Check = 'check';
    case Run = 'run';

    public function pulls(): bool
    {
        return self::Pull === $this || self::Changes === $this;
    }
}
