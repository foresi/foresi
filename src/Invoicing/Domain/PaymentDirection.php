<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * Which way the money of a payment went: received from the customer, or sent back
 * to them. A refund lowers what was paid on the invoices it is allocated to.
 */
enum PaymentDirection: string
{
    case Incoming = 'incoming';
    case Refund = 'refund';
}
