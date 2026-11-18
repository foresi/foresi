<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Shared\Domain\Money;

/**
 * A tax document on payment, or a paid advance invoice on which no VAT arose,
 * that an invoice may deduct, with the lines that deduct it.
 */
final readonly class Deductible
{
    /**
     * @param list<LineInput> $lines negative, one per rate
     */
    public function __construct(public string $id, public string $number, public string $advanceNumber, public Money $amount, public array $lines)
    {
    }
}
