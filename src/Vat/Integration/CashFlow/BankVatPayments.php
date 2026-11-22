<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FoundMovement;
use Foresi\CashFlow\Contract\MovementFinder;
use Foresi\CashFlow\Contract\MovementRule;
use Foresi\Vat\Contract\VatPayment;
use Foresi\Vat\Contract\VatPayments;
use Foresi\Vat\Contract\VatPaymentSource;

/**
 * The bank movements to and from the tax office, found by the cash flow's
 * movement finder: paid to the office's account with the variable symbol
 * (payable), got back from that account with any symbol, since the symbol
 * of a refund is not known (refund, unverified: refund_account), and paid
 * to the account with another symbol (other), which only a person places.
 * The amount never decides a rule.
 */
final readonly class BankVatPayments implements VatPaymentSource
{
    public function __construct(private MovementFinder $finder)
    {
    }

    public function paymentsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, string $account, ?string $variableSymbol): VatPayments
    {
        $rules = [new MovementRule(VatPayment::REFUND, $account, null, FlowDirection::In)];
        if (null !== $variableSymbol) {
            array_unshift($rules, new MovementRule(VatPayment::PAYABLE, $account, $variableSymbol, FlowDirection::Out));
            $rules[] = new MovementRule(VatPayment::OTHER, $account, null, FlowDirection::Out);
        } else {
            $rules[] = new MovementRule(VatPayment::PAYABLE, $account, null, FlowDirection::Out);
        }
        $found = $this->finder->find($from, $to, $rules);

        return new VatPayments(
            array_map(static fn (FoundMovement $movement): VatPayment => new VatPayment($movement->id, $movement->on, $movement->amount, FlowDirection::In === $movement->direction, $movement->ruleKey), $found->movements),
            $found->unavailable,
        );
    }
}
