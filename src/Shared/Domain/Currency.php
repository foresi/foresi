<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Supported ISO 4217 currencies.
 */
enum Currency: string
{
    case CZK = 'CZK';
    case EUR = 'EUR';
    case USD = 'USD';

    /**
     * Number of decimal digits of the minor unit (all supported currencies use 2).
     */
    public function minorUnits(): int
    {
        return 2;
    }
}
