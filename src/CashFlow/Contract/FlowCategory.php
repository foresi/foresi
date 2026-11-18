<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * The fixed categories of the forecast. A document takes the category of its
 * source: issued invoices are revenue, received documents purchases.
 */
enum FlowCategory: string
{
    case Revenue = 'revenue';
    case Purchases = 'purchases';
    case Rent = 'rent';
    case Software = 'software';
    case Payroll = 'payroll';
    case Taxes = 'taxes';
    case Insurance = 'insurance';
    case Vat = 'vat';
    case Loans = 'loans';
    case OwnerDraws = 'owner_draws';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
