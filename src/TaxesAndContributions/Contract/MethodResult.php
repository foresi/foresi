<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * The year computed with one expense method, with every step of it.
 */
final readonly class MethodResult
{
    /**
     * @param list<Step> $steps in the order of Step::KEYS
     */
    public function __construct(
        public ExpenseMethod $method,
        public Totals $totals,
        public array $steps,
    ) {
    }

    public function step(string $key): Step
    {
        foreach ($this->steps as $step) {
            if ($key === $step->key) {
                return $step;
            }
        }

        throw new \OutOfBoundsException(\sprintf('No step %s.', $key));
    }
}
