<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Calculation;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Czk;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Rules2026;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\Step;

/**
 * The profit of the self-employment (ZDP § 7): the income received less the
 * costs paid, on a cash basis, or less a lump sum of the income up to its cap.
 * The records count both with VAT before the VAT-payer day and without it
 * from then on.
 */
final readonly class ProfitStep implements PipelineStep
{
    public function apply(Calculation $calculation): Step
    {
        $amounts = $calculation->input->amounts;
        $calculation->income = $amounts->totalOf(AmountKind::Income)->counted->minor();
        $calculation->costs = $amounts->totalOf(AmountKind::Cost)->counted->minor();
        $input = ['income' => Czk::text($calculation->income), 'costs' => Czk::text($calculation->costs), 'method' => $calculation->method->value];
        if (ExpenseMethod::Actual === $calculation->method) {
            $calculation->expenses = $calculation->costs;
            $rule = 'Income less the costs actually paid, both on the cash basis.';
            $url = Rules2026::ZDP;
        } else {
            $rate = $calculation->rule('expenses.'.$calculation->method->value.'.rate');
            $cap = $calculation->rule('expenses.'.$calculation->method->value.'.cap');
            $calculation->expenses = min(max(0, Czk::percent($calculation->income, $rate->value)), $cap->minor());
            $input['lump_sum_rate'] = $rate->value;
            $input['lump_sum_cap'] = Czk::text($cap->minor());
            $rule = \sprintf('Income less %s %% of it as the lump sum, at most %s; the real costs do not count.', $rate->value, Czk::words($cap->minor()));
            $url = $rate->sourceUrl;
        }
        $calculation->profit = $calculation->income - $calculation->expenses;
        $input['expenses'] = Czk::text($calculation->expenses);

        return new Step(Step::PROFIT, $input, $rule, $url, Czk::text($calculation->profit));
    }
}
