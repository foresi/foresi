<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Everything the owner enters for one year beside the statuses, replacing what
 * the year had. All amounts are in CZK, decimals such as "12500.00".
 */
#[Operation(
    name: 'taxes.profile.year.set',
    method: 'PUT',
    path: '/api/v1/taxes-and-contributions/profile/years/{year}',
    tool: 'foresi_set_tax_year',
    scope: 'taxes:write',
    title: 'Set the tax inputs of a year',
    description: 'Replaces the inputs of one year of the owner profile: the expense methods to compare and the chosen one, the children and the spouse claimed, the deductions, the other partial bases, the last known tax, the previous year (its bases, overview day and months of activity), the return route, the start of the activity and an average wage override. '
        .'A field left out is cleared, so send the whole year as foresi_get_tax_profile returns it. All amounts are in CZK. Only the owner of the organisation may do this.',
    response: 'TaxYear',
    staffOnly: true,
)]
final readonly class SetYearInputsInput
{
    /**
     * @param list<mixed> $expenseMethods
     * @param list<mixed> $children
     * @param list<mixed> $deductions
     */
    public function __construct(
        #[Field('The year, such as 2026.', maxLength: 4)]
        public string $year,
        #[Field('The expense methods to compare, at least one; actual and lump_60 when left out.', enum: ['actual', 'lump_80', 'lump_60', 'lump_40', 'lump_30'], items: 'string')]
        public array $expenseMethods = ['actual', 'lump_60'],
        #[Field('The method the owner files with, one of expense_methods; null leaves it open.', enum: ['actual', 'lump_80', 'lump_60', 'lump_40', 'lump_30'])]
        public ?string $chosenMethod = null,
        #[Field('The children claimed: objects of label (an optional name of the owner\'s own, at most 100 characters), birth_date, order, ztp_p (true for a severe disability card) and months (0 to 12, 12 when left out). The order is 1, 2 or 3 for the third and further; leave it out or null and it follows the birth dates, the eldest first. Give it only when the parents agreed on another order.', items: Field::OBJECTS)]
        public array $children = [],
        #[Field('The months the spouse is claimed, 0 to 12; null claims no spouse.')]
        public ?int $spouseMonths = null,
        #[Field('Whether the spouse holds a severe disability card (ZTP/P).')]
        public bool $spouseZtpP = false,
        #[Field('Whether the spouse\'s own income stays at most 68,000 CZK.')]
        public bool $spouseLowIncome = false,
        #[Field('The deductions from the tax base: objects of type (gifts, housing_loan_interest, pension_products, life_insurance, long_term_investment, union_fees or exam_fees) and amount, each type once.', items: Field::OBJECTS)]
        public array $deductions = [],
        #[Field('The partial base from employment.')]
        public ?string $employmentBase = null,
        #[Field('The tax withheld from employment.')]
        public ?string $employmentTaxWithheld = null,
        #[Field('The partial base from rental.')]
        public ?string $rentalBase = null,
        #[Field('The other partial bases.')]
        public ?string $otherBase = null,
        #[Field('The last known tax, which sets the tax advances.')]
        public ?string $lastKnownTax = null,
        #[Field('The social insurance base of the previous year, which sets the advances until the overview is filed.')]
        public ?string $previousSocialBase = null,
        #[Field('The health insurance base of the previous year.')]
        public ?string $previousHealthBase = null,
        #[Field('The day the overviews of the previous year were filed.', format: 'date')]
        public ?string $previousOverviewFiledOn = null,
        #[Field('How the tax return is filed; electronic when left out.', enum: ['paper', 'electronic', 'advisor'])]
        public ?string $returnRoute = null,
        #[Field('The day the activity started, when it started in this year.', format: 'date')]
        public ?string $activityStart = null,
        #[Field('An average wage to use instead of the published one.')]
        public ?string $averageWageOverride = null,
        #[Field('The months of the previous year with an activity, at least for part of the month, 1 to 12; the previous year\'s bases are divided by them for the advances after its overview. Null counts the activity statuses of that year, or twelve when there are none.')]
        public ?int $previousActivityMonths = null,
    ) {
    }
}
