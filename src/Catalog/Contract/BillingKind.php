<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Contract;

/**
 * How a product is billed: once, again and again on a period, or by the hours
 * worked.
 *
 * A "time_based" product has the unit "h", its price per unit is the hourly
 * rate and it has no recurrence. The column holding the kind is a plain
 * string, so adding a case needs no data migration.
 */
enum BillingKind: string
{
    case OneOff = 'one_off';
    case Recurring = 'recurring';
    case TimeBased = 'time_based';
}
