<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A bank movement that meets a rule. The amount is positive, as the bank
 * booked it; the direction says which way it went.
 *
 * @param string $ruleKey the key of the rule it meets
 * @param string $id      the subject id of the bank movement
 */
final readonly class FoundMovement
{
    public function __construct(
        public string $ruleKey,
        public string $id,
        public \DateTimeImmutable $on,
        public Money $amount,
        public FlowDirection $direction,
    ) {
    }
}
