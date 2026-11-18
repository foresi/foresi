<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

enum PeriodKind: string
{
    case Week = 'week';
    case Month = 'month';
}
