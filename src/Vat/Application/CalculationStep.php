<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Domain\Money;

/**
 * One step of a period's calculation, so a person can follow it: what it
 * gives, the rule it follows and where its figures come from.
 */
final readonly class CalculationStep
{
    public const string OUTPUT = 'output';
    public const string INPUT = 'input';
    public const string RESULT = 'result';

    /**
     * @param list<string> $sources the keys of the document sources, or the steps the result is computed from
     */
    public function __construct(
        public string $name,
        public Money $amount,
        public string $rule,
        public array $sources,
    ) {
    }
}
