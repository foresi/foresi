<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure;

use App\Banking\Application\BankMovements;
use App\Party\Application\BankAccountUsage;

/**
 * A firm's bank account is used once a movement in its currency is recorded on it:
 * from then on its currency is what the bank booked in. Movements in another
 * currency only say the account was set up wrong, which may still be put right.
 */
final readonly class MovementUsage implements BankAccountUsage
{
    public function __construct(private BankMovements $movements)
    {
    }

    public function hasMovements(string $accountId): bool
    {
        return $this->movements->anyOnAccount($accountId);
    }
}
