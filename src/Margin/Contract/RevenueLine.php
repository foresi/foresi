<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Contract;

use Foresi\Shared\Domain\Money;

/**
 * One line of a revenue document: its own id, the id of the line it was
 * priced from when the source knows it (null until then) and its net in CZK.
 */
final readonly class RevenueLine
{
    public function __construct(
        public string $id,
        public ?string $sourceLineId,
        public Money $netCzk,
    ) {
    }
}
