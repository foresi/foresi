<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Contract\Event;

/**
 * Whether a payment brought money in or sent it back.
 */
enum PaymentDirection: string
{
    case Incoming = 'incoming';
    case Refund = 'refund';
}
