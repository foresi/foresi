<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\TaxesAndContributions\Contract\RuleRef;

/**
 * One Czech rule value from a day on, until the next value of its key (Goal
 * 7): a rate or share in per cent, or an amount in whole CZK, as a decimal
 * string, with where it is published. An unverified value is derived from the
 * statute before an authority publishes it.
 */
final readonly class RuleValue
{
    public function __construct(
        public string $key,
        public string $value,
        public \DateTimeImmutable $validFrom,
        public string $sourceUrl,
        public bool $unverified = false,
    ) {
    }

    public function ref(): RuleRef
    {
        return new RuleRef($this->key, $this->value, $this->validFrom, $this->sourceUrl, $this->unverified);
    }

    /**
     * The value in minor units of CZK, for a value that is an amount.
     */
    public function minor(): int
    {
        return Czk::minorOf($this->value);
    }
}
