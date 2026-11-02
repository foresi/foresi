<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Payment;

/**
 * Told when a payment that came from a bank movement is cancelled, inside the same
 * transaction, so the module that owns the movement can take it back. Invoicing
 * declares the port and knows nothing of what is behind it.
 */
interface PaymentSourceRelease
{
    /**
     * @param Payment $payment cancelled already, its allocations gone
     */
    public function released(Payment $payment): void;
}
