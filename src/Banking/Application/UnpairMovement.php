<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Domain\MovementState;
use Foresi\Invoicing\Application\CancelPayment;
use Foresi\Invoicing\Application\ConflictRetry;
use Foresi\Party\Application\TransactionalWork;

/**
 * Takes a pairing back: the payment is cancelled, its invoices are as unpaid as
 * before, and the movement is unmatched again and never paired by itself, so the
 * matcher does not undo the undo. The movement goes back through the release that
 * cancelling a bank payment calls, the one path for this screen and the invoice's.
 */
final readonly class UnpairMovement
{
    public function __construct(
        private BankMovements $movements,
        private CancelPayment $cancel,
        private TransactionalWork $work,
        private ConflictRetry $retry,
    ) {
    }

    /**
     * @throws PairingRefused      with NOT_PAIRED; nothing is stored
     * @throws UnknownBankMovement for an unknown movement
     */
    public function unpair(string $movementId): void
    {
        $this->retry->run(fn (): mixed => $this->work->run(function () use ($movementId): null {
            $movement = $this->movements->lock($movementId);
            $paymentId = $movement->paymentId();
            if (MovementState::Paired !== $movement->state() || null === $paymentId) {
                throw new PairingRefused(PairingRefused::NOT_PAIRED);
            }
            $this->cancel->cancel($paymentId);

            return null;
        }));
    }
}
