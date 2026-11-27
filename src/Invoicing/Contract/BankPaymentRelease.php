<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Told when a payment that came from a bank movement is cancelled, inside the
 * same transaction, so the module that owns the movement can take it back.
 * A module implements this and is tagged automatically.
 *
 * The movement is locked before the invoices of the payment, the order that
 * pairing and unpairing take them in too, so a cancellation never waits on one
 * of them while it holds what that one waits for.
 */
#[AutoconfigureTag(self::TAG)]
interface BankPaymentRelease
{
    public const string TAG = 'foresi.invoicing.bank_payment_release';

    /**
     * Takes the row of the movement for this transaction, before any invoice of
     * the payment is locked. It keeps the session.
     */
    public function lockMovement(string $bankMovementId): void;

    /**
     * The payment is cancelled already, its allocations gone.
     */
    public function released(string $bankMovementId, string $paymentId): void;
}
