<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * How long one VAT period of a registered payer is: a calendar month or a
 * calendar quarter.
 */
enum VatPeriodLength: string
{
    case Month = 'month';
    case Quarter = 'quarter';
}
