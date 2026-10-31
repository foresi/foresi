<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** Why a column was proposed for a field. */
enum MatchReason: string
{
    case Remembered = 'remembered';
    case Preset = 'preset';
    case SameName = 'same_name';
    case SimilarName = 'similar_name';
    case None = 'none';
}
