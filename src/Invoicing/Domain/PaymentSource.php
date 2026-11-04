<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * Where a payment came from: entered by a person, or paired from a movement on a
 * bank account of the firm.
 */
enum PaymentSource: string
{
    case Manual = 'manual';
    case BankMovement = 'bank_movement';
}
