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
 * about, and what the period warns about. The return amount a person
 * entered replaces the result as what the period expects; the bank
 * movements that pay it say what was paid, and the difference between the
 * two is information, never a reason to keep the period open.
 */
final readonly class PeriodView
{
    public Money $output;
    public Money $input;
    public Money $result;
    public Money $expected;
    public ?Money $paid;
    public ?Money $difference;

    /** @var list<string> */
    public array $warnings;

    /**
     * @param list<PaymentView> $payments the movements that pay it, oldest first
     */
    public function __construct(
        public VatPeriod $period,
        public \DateTimeImmutable $dueOn,
        public \DateTimeImmutable $refundOn,
        public PeriodAmounts $amounts,
        public ?Money $returnAmount = null,
        public PeriodState $state = PeriodState::Estimated,
        public array $payments = [],
        public ?SettledMark $mark = null,
    ) {
        $this->output = $amounts->output;
        $this->input = $amounts->input;
        $this->result = $amounts->result;
        $this->warnings = $amounts->warnings;
        $this->expected = $returnAmount ?? $amounts->result;
        $paid = null;
        foreach ($payments as $payment) {
            $paid = $paid instanceof Money ? $paid->plus($payment->signed()) : $payment->signed();
        }
        $this->paid = $paid;
        $this->difference = $paid?->minus($this->expected);
    }

    /**
     * The day the money is awaited: the due date of a period to pay, the
     * expected refund date of one to get back, none for a period of zero.
     */
    public function awaitedOn(): ?\DateTimeImmutable
    {
        return self::awaited($this->expected, $this->dueOn, $this->refundOn);
    }

    public static function awaited(Money $expected, \DateTimeImmutable $dueOn, \DateTimeImmutable $refundOn): ?\DateTimeImmutable
    {
        if ($expected->isZero()) {
            return null;
        }

        return $expected->isNegative() ? $refundOn : $dueOn;
    }
}
