<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure;

use Foresi\Party\Contract\BankAccountUsage;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * An account is used when any installed module records movements on it. With
 * none, such as without Banking, no account is ever in use.
 */
final readonly class CollectedBankAccountUsage implements BankAccountUsage
{
    /**
     * @param iterable<BankAccountUsage> $providers
     */
    public function __construct(
        #[AutowireIterator(BankAccountUsage::TAG)]
        private iterable $providers,
    ) {
    }

    public function hasMovements(string $accountId): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->hasMovements($accountId)) {
                return true;
            }
        }

        return false;
    }
}
