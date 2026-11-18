<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Foresi\Shared\Domain\Money;

/**
 * The balance of a firm's bank account a balance source knows, and the last
 * day its knowledge reaches, which decides whether the balance is stale.
 */
final readonly class AccountBalance
{
    public function __construct(
        public string $accountId,
        public Money $amount,
        public \DateTimeImmutable $asOf,
    ) {
    }
}
