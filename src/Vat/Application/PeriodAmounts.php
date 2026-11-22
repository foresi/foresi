<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Domain\Money;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\VatPeriod;

/**
 * The VAT of one period in CZK: the output VAT, the deductible input VAT and
 * the result, positive to pay and negative to get back. The breakdown keeps
 * the documents, the schedules and the plans apart; the steps say how the
 * result comes about. It holds the documents counted, and the labels of
 * those whose VAT has no CZK amount, which count zero.
 */
final readonly class PeriodAmounts
{
    public const string WARNING_MISSING_CZK_VAT = 'missing_czk_vat';

    public Money $output;
    public Money $input;
    public Money $result;

    /**
     * @param list<CalculationStep> $steps      output, input and result
     * @param list<DocumentVat>     $documents  the documents counted
     * @param list<string>          $warnings
     * @param list<string>          $missingCzk the labels of the documents counted without a CZK amount
     */
    public function __construct(
        public VatPeriod $period,
        public VatPart $documentsPart,
        public VatPart $schedulesPart,
        public VatPart $plansPart,
        public array $steps,
        public array $documents,
        public array $warnings,
        public array $missingCzk,
    ) {
        $this->output = $documentsPart->output->plus($schedulesPart->output)->plus($plansPart->output);
        $this->input = $documentsPart->input->plus($schedulesPart->input)->plus($plansPart->input);
        $this->result = $this->output->minus($this->input);
    }
}
