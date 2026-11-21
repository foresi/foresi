<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Support;

use Foresi\TaxesAndContributions\Contract\LevyPayment;
use Foresi\TaxesAndContributions\Contract\LevyPaymentSource;

/**
 * A bank that answers the movements it was given, or fails, and remembers
 * what it was asked.
 */
final class FakeLevyPayments implements LevyPaymentSource
{
    /** @var list<array{string, string, list<string>}> from, to and the authorities of the accounts */
    public array $asked = [];

    /**
     * @param list<LevyPayment> $payments
     */
    public function __construct(private readonly array $payments = [], private readonly bool $fails = false)
    {
    }

    public function paymentsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, array $accounts): iterable
    {
        $this->asked[] = [$from->format('Y-m-d'), $to->format('Y-m-d'), array_map(static fn (\Foresi\TaxesAndContributions\Contract\AuthorityAccountView $account): string => $account->authority, $accounts)];
        if ($this->fails) {
            throw new \RuntimeException('The bank broke.');
        }

        return $this->payments;
    }
}
