<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Shared\Domain\Money;

/**
 * A customer, by the name the documents hold, and what its open documents leave to pay.
 */
final readonly class Debtor
{
    public function __construct(public string $name, public Money $amount, public int $count)
    {
    }
}
