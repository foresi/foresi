<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Contract;

/**
 * Whether money comes into the firm or goes out of it.
 */
enum FlowDirection: string
{
    case In = 'in';
    case Out = 'out';
}
