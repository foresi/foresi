<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Whether a firm's bank account is used beyond its own record. Banking
 * implements it; Party only declares it, as it never uses Banking.
 */
#[AutoconfigureTag(self::TAG)]
interface BankAccountUsage
{
    public const string TAG = 'app.bank_account_usage';

    /**
     * Whether bank movements of the account are recorded, which fixes its currency.
     */
    public function hasMovements(string $accountId): bool;
}
