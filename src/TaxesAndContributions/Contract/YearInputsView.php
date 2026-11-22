<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * What the owner enters for one year, beside the statuses: the expense methods
 * to compare and the one chosen, the children and the spouse claimed, the
 * deductions, the other partial bases, the last known tax, the bases of the
 * previous year and its overview filed day, the return route, the day the
 * activity started, an average wage that overrides the published one and the
 * months of the previous year with an activity (1 to 12, null when not
 * entered). All amounts are in CZK.
 */
final readonly class YearInputsView
{
    /**
     * @param non-empty-list<ExpenseMethod> $expenseMethods
     * @param list<ChildInput>              $children
     * @param array<string, Money>          $deductions     by DeductionType value
     */
    public function __construct(
        public int $year,
        public array $expenseMethods = [ExpenseMethod::Actual, ExpenseMethod::Lump60],
        public ?ExpenseMethod $chosenMethod = null,
        public array $children = [],
        public ?SpouseInput $spouse = null,
        public array $deductions = [],
        public ?Money $employmentBase = null,
        public ?Money $employmentTaxWithheld = null,
        public ?Money $rentalBase = null,
        public ?Money $otherBase = null,
        public ?Money $lastKnownTax = null,
        public ?Money $previousSocialBase = null,
        public ?Money $previousHealthBase = null,
        public ?\DateTimeImmutable $previousOverviewFiledOn = null,
        public ReturnRoute $returnRoute = ReturnRoute::Electronic,
        public ?\DateTimeImmutable $activityStart = null,
        public ?Money $averageWageOverride = null,
        public ?int $previousActivityMonths = null,
    ) {
    }

    /**
     * The method the return of the year is filed with: the one chosen, or the
     * only one compared; null while the owner has not decided.
     */
    public function filedMethod(): ?ExpenseMethod
    {
        return match (true) {
            $this->chosenMethod instanceof ExpenseMethod => $this->chosenMethod,
            1 === \count($this->expenseMethods) => $this->expenseMethods[0],
            default => null,
        };
    }
}
