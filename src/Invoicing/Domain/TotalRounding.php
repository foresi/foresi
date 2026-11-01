<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * How the payable total is rounded.
 */
enum TotalRounding: string
{
    case None = 'none';
    case WholeUnit = 'whole_unit';
}
