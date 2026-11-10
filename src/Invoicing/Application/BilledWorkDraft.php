<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Currency;

/**
 * The firm, the customer, the currency and the lines of work to bill, as the
 * module that bills it proposes them. The tax of the lines is the firm's, as
 * on any new invoice.
 */
final readonly class BilledWorkDraft
{
    /**
     * @param list<BilledLine> $lines
     */
    public function __construct(
        public string $reference,
        public string $firmId,
        public string $customerId,
        public Currency $currency,
        public array $lines,
    ) {
    }
}
