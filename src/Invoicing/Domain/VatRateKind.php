<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * The kind of a VAT rate; the country says what it is on a date, and a kind
 * may have no rate on some dates (a second reduced rate a country had once).
 */
enum VatRateKind: string
{
    case Base = 'base';
    case Reduced = 'reduced';
    case SecondReduced = 'second_reduced';
}
