<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * A regime has no rules for the year asked, as it lies before its first one.
 */
final class RulesNotAvailable extends \DomainException
{
    public static function forYear(int $year, int $firstYear): self
    {
        return new self(\sprintf('No rules for %d; the first year with rules is %d.', $year, $firstYear));
    }
}
