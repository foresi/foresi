<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract\Event;

/**
 * Whether a planned item brings money in or takes it out.
 */
enum PlannedDirection: string
{
    case In = 'in';
    case Out = 'out';
}
