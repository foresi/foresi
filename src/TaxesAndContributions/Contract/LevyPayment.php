<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A bank movement that meets an authority's match rule: its account and
 * variable symbol (B4). The amount is positive, as the bank booked it.
 *
 * @param string $id the id of the bank movement
 */
final readonly class LevyPayment
{
    public function __construct(
        public Authority $authority,
        public string $id,
        public \DateTimeImmutable $on,
        public Money $amount,
        public bool $incoming = false,
    ) {
    }
}
