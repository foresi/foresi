<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Foresi\Shared\Domain\Money;

/**
 * One recipient of an allocation. A percent share has a percent, a fixed
 * share an amount in CZK, the share marked rest has neither, a whole share
 * has none of the three.
 */
final readonly class AllocationShare
{
    public function __construct(
        public Recipient $recipient,
        public ?string $percent = null,
        public ?Money $amountCzk = null,
        public bool $rest = false,
    ) {
    }
}
