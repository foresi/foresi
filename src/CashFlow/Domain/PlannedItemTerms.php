<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\Recurrence;

/**
 * What a planned item says, checked already: a positive amount, a name, and
 * either a day or a schedule that starts on that day. A VAT mode other than
 * none comes with a rate from 0 to 100 percent with at most two decimals,
 * kept with two decimals, and none comes without one.
 */
final readonly class PlannedItemTerms
{
    public function __construct(
        public string $name,
        public FlowCategory $category,
        public Money $amount,
        public \DateTimeImmutable $firstOn,
        public ?Recurrence $recurrence,
        public ?Party $counterparty,
        public bool $included,
        public MatchRule $matchRule,
        public PlannedVatMode $vatMode = PlannedVatMode::None,
        public ?string $vatRate = null,
    ) {
        if ('' === trim($name) || $amount->minor() <= 0) {
            throw new \InvalidArgumentException('A planned item has a name and a positive amount.');
        }
        if ((PlannedVatMode::None === $vatMode) !== (null === $vatRate)
            || (null !== $vatRate && 1 !== preg_match('/^(?:100\.00|[0-9]{1,2}\.[0-9]{2})\z/', $vatRate))) {
            throw new \InvalidArgumentException('A planned item with a VAT mode has a rate from 0.00 to 100.00, and one without has none.');
        }
    }

    /**
     * The rate without the zeros its decimals end in: "21.00" is "21", "12.50" is "12.5".
     */
    public static function rate(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
