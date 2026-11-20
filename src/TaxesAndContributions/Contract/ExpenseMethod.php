<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * How the costs of a year are counted: the actual costs, or a lump sum of 80,
 * 60, 40 or 30 per cent of the income.
 */
enum ExpenseMethod: string
{
    case Actual = 'actual';
    case Lump80 = 'lump_80';
    case Lump60 = 'lump_60';
    case Lump40 = 'lump_40';
    case Lump30 = 'lump_30';
}
