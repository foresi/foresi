<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The bank movements to and from the account of the tax office. Cash flow
 * gives them from the bank through Integration/CashFlow (ADR 0033); without
 * it nothing is matched.
 */
#[AutoconfigureTag(self::TAG)]
interface VatPaymentSource
{
    public const string TAG = 'foresi.vat.payment_source';

    /**
     * The movements booked between the two days, both included, that go to or
     * come from the account, each with the rule it meets (VatPayment).
     *
     * @param ?string $variableSymbol the symbol of a payment; null asks for the account only
     */
    public function paymentsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, string $account, ?string $variableSymbol): VatPayments;
}
