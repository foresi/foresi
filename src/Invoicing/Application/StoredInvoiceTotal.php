<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Shared\Domain\Money;

/**
 * One stored document: its day of issue and what it leaves to pay.
 */
final readonly class StoredInvoiceTotal
{
    public function __construct(public string $invoiceId, public \DateTimeImmutable $issueDate, public Money $payable)
    {
    }
}
