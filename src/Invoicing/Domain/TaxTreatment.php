<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * How a line is taxed.
 */
enum TaxTreatment: string
{
    case Standard = 'standard';
    case ReverseCharge = 'reverse_charge';
    case Exempt = 'exempt';
    case NotApplicable = 'not_applicable';

    /**
     * Only a standard supply carries a VAT rate; reverse charge, exempt and
     * outside-scope supplies show none.
     */
    public function hasRate(): bool
    {
        return self::Standard === $this;
    }
}
