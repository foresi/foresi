<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * A rule value the calculation used: its key, its value as a decimal string,
 * the day it holds from, where it is published, and whether it still waits
 * for an authority to confirm it.
 */
final readonly class RuleRef
{
    public function __construct(
        public string $key,
        public string $value,
        public \DateTimeImmutable $validFrom,
        public string $sourceUrl,
        public bool $unverified = false,
    ) {
    }
}
