<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Domain;

/**
 * Which time of a project uses its time budget: all of it, as Harvest counts
 * by default, or only the billable time, so that work done non-billable, such
 * as flat-fee operation, leaves the budget alone.
 */
enum BudgetCounting: string
{
    case All = 'all';
    case Billable = 'billable';
}
