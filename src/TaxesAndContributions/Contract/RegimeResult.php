<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A year computed by a regime: the rules it used and how sure they are, one
 * result per expense method compared, the cheaper method and the break-even
 * (how much more real cost would make the actual costs cost as much as the
 * cheapest lump sum), the credits, the months counted, the payments of the
 * year as they fall due (its cash view), and what the owner or an accountant
 * should know. It is always an estimate.
 */
final readonly class RegimeResult
{
    /**
     * @param list<RuleRef>      $rulesUsed
     * @param list<MethodResult> $methods
     * @param list<CreditLine>   $credits
     * @param list<MonthStatus>  $months     January to the month of the cut-off
     * @param list<string>       $warnings
     * @param list<string>       $unverified
     * @param list<string>       $knownGaps
     * @param list<DuePayment>   $payments   every advance of the year, and the settlement once the cut-off is 31 December; all expected
     */
    public function __construct(
        public string $regime,
        public int $ruleYear,
        public RulesStatus $rules,
        public array $rulesUsed,
        public array $methods,
        public ?ExpenseMethod $cheaperMethod,
        public ?Money $breakEven,
        public array $credits,
        public array $months,
        public array $warnings,
        public array $unverified,
        public array $knownGaps,
        public array $payments = [],
    ) {
    }

    public function method(ExpenseMethod $method): MethodResult
    {
        foreach ($this->methods as $result) {
            if ($method === $result->method) {
                return $result;
            }
        }

        throw new \OutOfBoundsException(\sprintf('No result for %s.', $method->value));
    }
}
