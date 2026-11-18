<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure;

use Foresi\Invoicing\Application\PaymentSourceRelease;
use Foresi\Invoicing\Domain\Payment;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tells every installed module that owns where payments come from, such as
 * Banking with its movements. Each one ignores a payment of another source;
 * with none installed, a cancellation releases nothing.
 */
final readonly class CollectedPaymentSourceRelease implements PaymentSourceRelease
{
    /**
     * @param iterable<PaymentSourceRelease> $providers
     */
    public function __construct(
        #[AutowireIterator(PaymentSourceRelease::TAG)]
        private iterable $providers,
    ) {
    }

    public function lockSource(Payment $payment): void
    {
        foreach ($this->providers as $provider) {
            $provider->lockSource($payment);
        }
    }

    public function released(Payment $payment): void
    {
        foreach ($this->providers as $provider) {
            $provider->released($payment);
        }
    }
}
