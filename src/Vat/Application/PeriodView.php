<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Domain\Money;
use Foresi\Vat\Contract\VatPeriod;

/**
 * One VAT period of the firm as the module answers it: the period clipped
 * to the payer's stretch, the due date and the expected refund date of the
 * whole period, the output VAT, the deductible input VAT and the result
 * (positive to pay, negative to get back), all in CZK, and what the period
 * warns about.
 */
final readonly class PeriodView
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public VatPeriod $period,
        public \DateTimeImmutable $dueOn,
        public \DateTimeImmutable $refundOn,
        public Money $output,
        public Money $input,
        public Money $result,
        public array $warnings = [],
    ) {
    }
}
