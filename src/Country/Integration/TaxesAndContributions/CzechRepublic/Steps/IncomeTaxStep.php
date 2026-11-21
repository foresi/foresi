<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Calculation;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Czk;
use Foresi\TaxesAndContributions\Contract\Step;

/**
 * The income tax before the credits (ZDP § 16): 15 % of the base up to 36
 * average wages and 23 % of the part above, rounded up to whole CZK.
 */
final readonly class IncomeTaxStep implements PipelineStep
{
    public function apply(Calculation $calculation): Step
    {
        $rate = $calculation->rule('tax.rate');
        $higher = $calculation->rule('tax.higher_rate');
        $multiple = $calculation->rule('tax.threshold_multiple');
        $threshold = $calculation->rule('average_wage')->minor() * (int) $multiple->value;
        $lower = min($calculation->taxBase, $threshold);
        $above = max(0, $calculation->taxBase - $threshold);
        $calculation->tax = Czk::upToCzk(Czk::percent($lower, $rate->value) + Czk::percent($above, $higher->value));

        return new Step(
            Step::INCOME_TAX,
            ['tax_base' => Czk::text($calculation->taxBase), 'threshold' => Czk::text($threshold), 'rate' => $rate->value, 'higher_rate' => $higher->value],
            \sprintf('%s %% of the base up to %s average wages, %s %% of the part above; rounded up.', $rate->value, $multiple->value, $higher->value),
            $rate->sourceUrl,
            Czk::text($calculation->tax),
        );
    }
}
