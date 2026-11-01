<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * How a unit price is meant: with VAT in it, without it, or only as a base that carries no VAT.
 */
enum PriceType: string
{
    case WithVat = 'with_vat';
    case WithoutVat = 'without_vat';
    case BaseOnly = 'base_only';
}
