<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

/**
 * How the cost of a line is split: the whole of it to one recipient, by
 * percent, or in fixed amounts with the rest to one recipient.
 */
enum AllocationKind: string
{
    case Whole = 'whole';
    case Percent = 'percent';
    case Fixed = 'fixed';
}
