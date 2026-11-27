<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Support;

use Foresi\Banking\Application\PaymentPairing;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Shared\Domain\Currency;

/**
 * The pairing of the container, writing each document it locks to the log.
 */
final readonly class LoggingPaymentPairing implements PaymentPairing
{
    public function __construct(private PaymentPairing $pairing, private LockLog $log)
    {
    }

    public function isAvailable(): bool
    {
        return $this->pairing->isAvailable();
    }

    public function lock(array $documentIds): void
    {
        foreach ($documentIds as $id) {
            $this->log->locked($id);
        }
        $this->pairing->lock($documentIds);
    }

    public function isSettled(string $documentId, bool $incoming): bool
    {
        return $this->pairing->isSettled($documentId, $incoming);
    }

    public function pay(BankMovement $movement, array $allocations): string
    {
        return $this->pairing->pay($movement, $allocations);
    }

    public function cancel(string $paymentId): void
    {
        $this->pairing->cancel($paymentId);
    }

    public function settlementLimit(Currency $currency): ?int
    {
        return $this->pairing->settlementLimit($currency);
    }

    public function isLive(string $movementId): bool
    {
        return $this->pairing->isLive($movementId);
    }

    public function documentsOf(array $paymentIds): array
    {
        return $this->pairing->documentsOf($paymentIds);
    }

    public function documentUrl(string $documentId): string
    {
        return $this->pairing->documentUrl($documentId);
    }
}
