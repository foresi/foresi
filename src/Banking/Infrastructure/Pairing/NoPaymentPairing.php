<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Pairing;

use Foresi\Banking\Application\PairingRefused;
use Foresi\Banking\Application\PaymentPairing;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Shared\Domain\Currency;

/**
 * Nothing pays documents from movements when no module that keeps them, such as
 * Invoicing, is installed: every movement stays unmatched, and the screens offer
 * no pairing. A movement paired before such a module was removed keeps its
 * payment id, and nothing names its documents.
 */
final readonly class NoPaymentPairing implements PaymentPairing
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function lock(array $documentIds): void
    {
        if ([] !== $documentIds) {
            throw new PairingRefused(PairingRefused::UNKNOWN_DOCUMENT);
        }
    }

    public function isSettled(string $documentId, bool $incoming): bool
    {
        throw new PairingRefused(PairingRefused::UNKNOWN_DOCUMENT);
    }

    public function pay(BankMovement $movement, array $allocations): string
    {
        throw new PairingRefused(PairingRefused::NO_PAIRING);
    }

    public function cancel(string $paymentId): void
    {
        throw new PairingRefused(PairingRefused::NO_PAIRING);
    }

    public function settlementLimit(Currency $currency): ?int
    {
        return null;
    }

    public function isLive(string $movementId): bool
    {
        return false;
    }

    public function documentsOf(array $paymentIds): array
    {
        return [];
    }

    public function documentUrl(string $documentId): string
    {
        throw new \LogicException('Without a pairing no payment names a document.');
    }
}
