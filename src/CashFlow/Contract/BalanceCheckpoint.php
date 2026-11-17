<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Contract;

use App\Shared\Domain\Money;

/**
 * The balance of a firm's bank account at the end of a day, as someone or a
 * statement import entered it.
 */
final readonly class BalanceCheckpoint
{
    public function __construct(
        public string $accountId,
        public string $firmId,
        public Money $amount,
        public \DateTimeImmutable $on,
    ) {
    }
}
