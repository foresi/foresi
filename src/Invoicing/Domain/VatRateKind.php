<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * The kind of a VAT rate; the country says what it is on a date.
 */
enum VatRateKind: string
{
    case Base = 'base';
    case Reduced = 'reduced';
}
