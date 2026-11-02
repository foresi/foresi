<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * An invoice and the firm's day its first reminder would go out.
 */
final readonly class PlannedReminder
{
    public function __construct(
        public InvoiceRow $invoice,
        public \DateTimeImmutable $on,
    ) {
    }
}
