<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Calculation;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Czk;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\DeductionType;
use Foresi\TaxesAndContributions\Contract\Step;

/**
 * The tax base (ZDP § 5, § 15, § 16): the profit with the other partial bases
 * the owner entered, less the deductions up to their caps, rounded down to
 * whole hundreds. Pension and life products share one cap; gifts count when
 * they reach 2 % of the base or 1,000 CZK, up to 15 % of it. Union and exam
 * fees are not computed yet.
 */
final readonly class TaxBaseStep implements PipelineStep
{
    public function apply(Calculation $calculation): Step
    {
        $inputs = $calculation->inputs;
        $minor = static fn (?Money $money): int => $money?->minor() ?? 0;
        $deduction = static fn (DeductionType $type): int => $minor($inputs->deductions[$type->value] ?? null);
        $profit = $calculation->ordinaryProfit();
        $partial = $profit + $minor($inputs->employmentBase) + $minor($inputs->rentalBase) + $minor($inputs->otherBase);
        $pensionCap = $calculation->rule('deduction.pension_and_life_cap');
        $pension = min($deduction(DeductionType::PensionProducts) + $deduction(DeductionType::LifeInsurance) + $deduction(DeductionType::LongTermInvestment), $pensionCap->minor());
        $housing = min($deduction(DeductionType::HousingLoanInterest), $calculation->rule('deduction.housing_loan_cap')->minor());
        $gifts = $deduction(DeductionType::Gifts);
        $giftsCounted = 0;
        if ($gifts > 0 && $partial > 0 && ($gifts >= $calculation->rule('deduction.gifts_minimum')->minor() || $gifts > Czk::percent($partial, $calculation->rule('deduction.gifts_minimum_share')->value))) {
            $giftsCounted = min($gifts, Czk::percent($partial, $calculation->rule('deduction.gifts_maximum_share')->value));
        }
        if ($deduction(DeductionType::UnionFees) > 0 || $deduction(DeductionType::ExamFees) > 0) {
            $calculation->warn(Calculation::WARNING_DEDUCTION_NOT_COMPUTED);
        }
        $deductions = min(max(0, $partial), $pension + $housing + $giftsCounted);
        $calculation->taxBase = Czk::downToHundreds(max(0, $partial - $deductions));

        return new Step(
            Step::TAX_BASE,
            [
                'profit' => Czk::text($profit),
                'other_partial_bases' => Czk::text($partial - $profit),
                'pension_and_life' => Czk::text($pension),
                'housing_loan_interest' => Czk::text($housing),
                'gifts' => Czk::text($giftsCounted),
                'deductions' => Czk::text($deductions),
            ],
            'The profit and the other partial bases, less the deductions within their caps, rounded down to whole hundreds.',
            $pensionCap->sourceUrl,
            Czk::text($calculation->taxBase),
        );
    }
}
