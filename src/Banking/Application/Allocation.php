<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Shared\Domain\Money;

/**
 * The part of a movement one document is to get, and the small difference
 * written off on it, if any. Positive for a refund too.
 */
final readonly class Allocation
{
    public function __construct(public string $documentId, public Money $amount, public ?Money $settlement = null)
    {
    }
}
