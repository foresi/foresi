<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * The output VAT and the deductible input VAT of one part of a period, in
 * CZK: its documents, its schedules or its plans.
 */
final readonly class VatPart
{
    public function __construct(public Money $output, public Money $input)
    {
    }

    public static function zero(): self
    {
        $zero = Money::ofMinor(0, Currency::of('CZK'));

        return new self($zero, $zero);
    }
}
