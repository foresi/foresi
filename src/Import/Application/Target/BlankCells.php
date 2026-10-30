<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Target;

/** What an empty cell does to an existing record. */
enum BlankCells
{
    /** The value stays as it is. */
    case Keep;
    /** The value is cleared. */
    case Clear;
}
