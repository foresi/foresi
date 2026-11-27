<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

use Foresi\Shared\Domain\Money;

/**
 * The part of a bank payment one invoice is to get, and the small difference
 * written off on it, if any. Positive for a refund too.
 */
final readonly class BankPaymentPart
{
    public function __construct(public string $invoiceId, public Money $amount, public ?Money $settlement = null)
    {
    }
}
