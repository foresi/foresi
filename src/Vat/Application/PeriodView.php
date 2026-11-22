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
 * (positive to pay, negative to get back), all in CZK, with how they come
 * about, and what the period warns about.
 */
final readonly class PeriodView
{
    public Money $output;
    public Money $input;
    public Money $result;

    /** @var list<string> */
    public array $warnings;

    public function __construct(
        public VatPeriod $period,
        public \DateTimeImmutable $dueOn,
        public \DateTimeImmutable $refundOn,
        public PeriodAmounts $amounts,
    ) {
        $this->output = $amounts->output;
        $this->input = $amounts->input;
        $this->result = $amounts->result;
        $this->warnings = $amounts->warnings;
    }
}
