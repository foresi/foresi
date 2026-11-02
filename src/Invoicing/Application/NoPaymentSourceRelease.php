<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Payment;

/**
 * Nothing to take back while no module records where payments come from.
 */
final readonly class NoPaymentSourceRelease implements PaymentSourceRelease
{
    public function released(Payment $payment): void
    {
    }
}
