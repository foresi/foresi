<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Integration\Invoicing;

use Foresi\Banking\Application\BankMovements;
use Foresi\Banking\Application\UnknownBankMovement;
use Foresi\Invoicing\Contract\BankPaymentRelease;

/**
 * A bank payment was cancelled, on the invoice or by unpairing: its movement is
 * unmatched again and blocked from pairing by itself, in the same transaction. It
 * only changes the movement, and never cancels anything, so the two paths cannot
 * call each other round.
 */
final readonly class ReleaseMovement implements BankPaymentRelease
{
    public function __construct(private BankMovements $movements)
    {
    }

    public function lockMovement(string $bankMovementId): void
    {
        try {
            $this->movements->lock($bankMovementId);
        } catch (UnknownBankMovement) {
            // Nothing recorded to lock; the payment is cancelled all the same.
        }
    }

    public function released(string $bankMovementId, string $paymentId): void
    {
        try {
            $movement = $this->movements->lock($bankMovementId);
        } catch (UnknownBankMovement) {
            // Nothing recorded to take back; the payment is cancelled all the same.
            return;
        }
        if ($movement->paymentId() === $paymentId) {
            $movement->release();
        }
    }
}
