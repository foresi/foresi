<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

/**
 * How a received document was paid. The names follow the payments of issued
 * invoices; "other" covers an offset, a payment by a third party and the like.
 */
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Cash = 'cash';
    case Other = 'other';
}
