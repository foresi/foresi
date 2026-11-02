<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * The part of a payment one invoice is to get, and the small difference written
 * off on it, if any.
 */
final readonly class AllocationRequest
{
    public function __construct(public string $invoiceId, public Money $amount, public ?Money $settlement = null)
    {
    }
}
