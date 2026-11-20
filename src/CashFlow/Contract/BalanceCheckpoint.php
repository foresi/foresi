<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Foresi\Shared\Domain\Money;

/**
 * The balance of a bank account of the firm at the end of a day, as someone or a
 * statement import entered it.
 */
final readonly class BalanceCheckpoint
{
    public function __construct(
        public string $accountId,
        public Money $amount,
        public \DateTimeImmutable $on,
    ) {
    }
}
