<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\RegistryCheck;

/**
 * The unreliable VAT payer check of one VAT id, with the bank accounts the
 * payer has published (account number and bank code, or IBAN, as returned).
 */
final readonly class ReliabilityResult
{
    /**
     * @param list<string> $publishedBankAccounts
     */
    public function __construct(
        public RegistryCheck $check,
        public array $publishedBankAccounts = [],
    ) {
    }
}
