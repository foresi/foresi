<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\RegistryCheck;

/**
 * What the public registers answered for one VAT id: fetched first, stored
 * afterwards, so no transaction waits for a register.
 */
final readonly class RegistryOutcome
{
    /**
     * @param list<string> $publishedBankAccounts
     */
    public function __construct(
        public RegistryCheck $vies,
        public ?RegistryCheck $reliability,
        public array $publishedBankAccounts,
    ) {
    }
}
