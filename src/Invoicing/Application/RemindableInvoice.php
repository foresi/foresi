<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * An invoice that is due for its next reminder: which one it would be, and the
 * firm's day of the previous one, if any.
 */
final readonly class RemindableInvoice
{
    public function __construct(
        public string $invoiceId,
        public int $nextStep,
        public ?\DateTimeImmutable $lastReminderOn,
    ) {
    }
}
