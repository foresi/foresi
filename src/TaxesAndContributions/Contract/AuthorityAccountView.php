<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * Where the owner pays an authority: its account and the payment reference
 * (the variable symbol), the match rule for the payments of its advances.
 */
final readonly class AuthorityAccountView
{
    public function __construct(
        public string $authority,
        public string $account,
        public string $variableSymbol,
    ) {
    }
}
