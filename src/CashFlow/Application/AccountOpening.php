<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\Party\Domain\FirmBankAccount;
use App\Shared\Domain\Money;

/**
 * Where one bank account of the firm starts: its balance in its currency and
 * in CZK, and the last day that balance knows. An account without a
 * checkpoint has no balance.
 */
final readonly class AccountOpening
{
    public function __construct(
        public FirmBankAccount $account,
        public ?Money $balance,
        public ?Money $amount,
        public ?\DateTimeImmutable $asOf,
        public ?string $source,
    ) {
    }
}
