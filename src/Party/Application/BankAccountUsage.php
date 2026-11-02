<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * Whether a firm's bank account is used beyond its own record. Banking
 * implements it; Party only declares it, as it never uses Banking.
 */
interface BankAccountUsage
{
    /**
     * Whether bank movements of the account are recorded, which fixes its currency.
     */
    public function hasMovements(string $accountId): bool;
}
