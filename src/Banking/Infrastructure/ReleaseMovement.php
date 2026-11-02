<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure;

use App\Banking\Application\BankMovements;
use App\Banking\Application\UnknownBankMovement;
use App\Invoicing\Application\PaymentSourceRelease;
use App\Invoicing\Domain\Payment;

/**
 * A bank payment was cancelled, on the invoice or by unpairing: its movement is
 * unmatched again and blocked from pairing by itself, in the same transaction. It
 * only changes the movement, and never cancels anything, so the two paths cannot
 * call each other round.
 */
final readonly class ReleaseMovement implements PaymentSourceRelease
{
    public function __construct(private BankMovements $movements)
    {
    }

    public function released(Payment $payment): void
    {
        $id = $payment->bankMovementId();
        if (null === $id) {
            return;
        }
        try {
            $movement = $this->movements->lock($id);
        } catch (UnknownBankMovement) {
            // Nothing recorded to take back; the payment is cancelled all the same.
            return;
        }
        if ($movement->paymentId() === $payment->id()) {
            $movement->release();
        }
    }
}
