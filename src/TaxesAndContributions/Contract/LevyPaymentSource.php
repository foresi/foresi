<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The bank movements that pay the levies (B4): those that meet the account
 * and the variable symbol of an authority in the profile. Cash flow gives them
 * from the bank, through Integration/CashFlow (ADR 0033).
 */
#[AutoconfigureTag(self::TAG)]
interface LevyPaymentSource
{
    public const string TAG = 'foresi.taxes_and_contributions.levy_payment_source';

    /**
     * The movements booked between the two days, both included, that meet
     * one of the accounts, and the bank sources that could not be read.
     *
     * @param list<AuthorityAccountView> $accounts
     */
    public function paymentsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, array $accounts): LevyPayments;
}
