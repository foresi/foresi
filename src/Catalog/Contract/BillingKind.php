<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Contract;

/**
 * How a product is billed: once, or again and again on a period.
 *
 * "time_based" (billed by the hours worked) is reserved for a later case. The
 * column holding the kind is a plain string, so adding a case needs no data
 * migration.
 */
enum BillingKind: string
{
    case OneOff = 'one_off';
    case Recurring = 'recurring';
}
