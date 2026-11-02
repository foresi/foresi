<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * The usage while no bank movements are recorded anywhere.
 */
final readonly class NoBankAccountUsage implements BankAccountUsage
{
    public function hasMovements(string $accountId): bool
    {
        return false;
    }
}
