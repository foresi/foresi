<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Calculation;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Czk;
use Foresi\TaxesAndContributions\Contract\CreditLine;
use Foresi\TaxesAndContributions\Contract\Step;

/**
 * The child benefit (ZDP § 35c): by the order of each child claimed, 1/12 for
 * each month, double with a ZTP/P card. It lowers the tax left after the
 * credits first, and what remains is paid out as a bonus when it is at least
 * 100 CZK and the income of the year reaches six minimum wages.
 */
final readonly class ChildBenefitStep implements PipelineStep
{
    public function apply(Calculation $calculation): Step
    {
        $first = $calculation->rule('child.first');
        $children = $calculation->inputs->children;
        $total = 0;
        foreach ($children as $child) {
            $rate = $calculation->rule(match (true) {
                $child->order <= 1 => 'child.first',
                2 === $child->order => 'child.second',
                default => 'child.third',
            });
            $total += intdiv($rate->minor() * max(0, min(12, $child->months)), 12) * ($child->ztpP ? 2 : 1);
        }
        $asCredit = min($total, $calculation->taxAfterCredits);
        $rest = $total - $asCredit;
        $minimumIncome = $calculation->rule('minimum_wage')->minor() * (int) $calculation->rule('child.bonus_income_multiple')->value;
        $income = $calculation->income + ($calculation->inputs->employmentBase?->minor() ?? 0);
        $incomeMet = $income >= $minimumIncome;
        $bonusMinimum = $calculation->rule('child.bonus_minimum')->minor();
        $calculation->childBonus = $rest >= $bonusMinimum && $incomeMet ? $rest : 0;
        $calculation->incomeTax = $calculation->taxAfterCredits - $asCredit;
        $calculation->credits[] = new CreditLine('child_benefit', $total > 0, Czk::money($total), \sprintf('Each child you claim, by its order: %s, %s and %s a year, 1/12 for each month, double with a ZTP/P card. What the tax cannot take is paid out from %s when the income reaches %s.', Czk::words($first->minor()), Czk::words($calculation->rule('child.second')->minor()), Czk::words($calculation->rule('child.third')->minor()), Czk::words($bonusMinimum), Czk::words($minimumIncome)));

        return new Step(
            Step::CHILD_BENEFIT,
            [
                'children' => (string) \count($children),
                'benefit' => Czk::text($total),
                'tax_after_credits' => Czk::text($calculation->taxAfterCredits),
                'as_credit' => Czk::text($asCredit),
                'income' => Czk::text($income),
                'minimum_income' => Czk::text($minimumIncome),
                'bonus' => Czk::text($calculation->childBonus),
            ],
            'The child benefit lowers the tax first; the rest is a bonus paid out when it is at least the minimum and the income reaches six minimum wages.',
            $first->sourceUrl,
            Czk::text($calculation->incomeTax),
        );
    }
}
