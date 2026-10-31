<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** What an empty cell does to an existing record. */
enum BlankCells: string
{
    /** The value stays as it is. */
    case Keep = 'keep';
    /** The value is cleared. */
    case Clear = 'clear';
}
