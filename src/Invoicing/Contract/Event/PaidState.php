<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Contract\Event;

/**
 * How far a payable document is settled once it counts as paid.
 */
enum PaidState: string
{
    case Paid = 'paid';
    case Overpaid = 'overpaid';
}
