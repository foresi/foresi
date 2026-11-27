<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure;

use Foresi\Invoicing\Application\PaymentSourceRelease;
use Foresi\Invoicing\Contract\BankPaymentRelease;
use Foresi\Invoicing\Domain\Payment;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tells every installed module that owns bank movements, such as Banking, by
 * the ids of the movement and the payment. Only a payment from a movement is
 * told; each module ignores a movement that is not its own, and with none
 * installed, a cancellation releases nothing.
 */
final readonly class CollectedPaymentSourceRelease implements PaymentSourceRelease
{
    /**
     * @param iterable<BankPaymentRelease> $providers
     */
    public function __construct(
        #[AutowireIterator(BankPaymentRelease::TAG)]
        private iterable $providers,
    ) {
    }

    public function lockSource(Payment $payment): void
    {
        $movementId = $payment->bankMovementId();
        if (null === $movementId) {
            return;
        }
        foreach ($this->providers as $provider) {
            $provider->lockMovement($movementId);
        }
    }

    public function released(Payment $payment): void
    {
        $movementId = $payment->bankMovementId();
        if (null === $movementId) {
            return;
        }
        foreach ($this->providers as $provider) {
            $provider->released($movementId, $payment->id());
        }
    }
}
