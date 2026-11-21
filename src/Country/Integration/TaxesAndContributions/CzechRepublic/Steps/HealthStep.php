<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Calculation;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Czk;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Month;
use Foresi\TaxesAndContributions\Contract\Step;

/**
 * Health insurance for the year: 50 % of the profit, at least half the
 * average wage for each active month without the exemption from the minimum,
 * times 13.5 %, rounded up. Health insurance knows no secondary activity, and
 * has no maximum.
 */
final readonly class HealthStep implements PipelineStep
{
    public function apply(Calculation $calculation): Step
    {
        $rate = $calculation->rule('health.rate');
        $share = $calculation->rule('health.profit_share');
        $active = array_filter($calculation->months, static fn (Month $month): bool => $month->isActive());
        $input = ['profit' => Czk::text($calculation->profit), 'active_months' => (string) \count($active)];
        if ([] === $active) {
            $calculation->health = 0;

            return new Step(Step::HEALTH, $input, 'No active month: no health insurance as a sole trader.', $rate->sourceUrl, Czk::text(0));
        }
        $minimum = 0;
        $withMinimum = 0;
        foreach ($active as $month) {
            if (!$month->healthMinimumExempt) {
                $minimum += Czk::percent($calculation->rule('average_wage', $month->first)->minor(), $calculation->rule('health.minimum_share', $month->first)->value);
                ++$withMinimum;
            }
        }
        $profitShare = Czk::percent(max(0, $calculation->profit), $share->value);
        $base = Czk::upToCzk(max($profitShare, $minimum));
        $calculation->health = Czk::percentUp($base, $rate->value);
        $input += ['months_with_minimum' => (string) $withMinimum, 'profit_share' => Czk::text($profitShare), 'minimum_base' => Czk::text($minimum), 'base' => Czk::text($base), 'rate' => $rate->value];

        return new Step(
            Step::HEALTH,
            $input,
            \sprintf('%s %% of the profit, at least %s %% of the average wage for each active month with the minimum; times %s %%, rounded up.', $share->value, $calculation->rule('health.minimum_share')->value, $rate->value),
            $rate->sourceUrl,
            Czk::text($calculation->health),
        );
    }
}
