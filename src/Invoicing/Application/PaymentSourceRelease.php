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
 *
 * The source is locked before the invoices of the payment, the order that pairing
 * and unpairing take them in too, so a cancellation never waits on one of them
 * while it holds what that one waits for.
 */
interface PaymentSourceRelease
{
    /**
     * Takes the row of where the payment came from for this transaction, before any
     * invoice of the payment is locked. A payment of no such source locks nothing.
     * It keeps the session. Call it inside TransactionalWork.
     */
    public function lockSource(Payment $payment): void;

    /**
     * @param Payment $payment cancelled already, its allocations gone
     */
    public function released(Payment $payment): void;
}
