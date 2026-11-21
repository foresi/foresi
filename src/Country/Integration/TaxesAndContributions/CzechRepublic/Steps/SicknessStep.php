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
 * Voluntary sickness insurance: for each month it is on, 2.7 % of the monthly
 * base the owner chose, at least the minimum base, rounded up per month.
 */
final readonly class SicknessStep implements PipelineStep
{
    public function apply(Calculation $calculation): Step
    {
        $rate = $calculation->rule('sickness.rate');
        $months = array_filter($calculation->months, static fn (Month $month): bool => $month->sicknessInsurance && $month->isActive());
        $total = 0;
        $bases = [];
        foreach ($months as $month) {
            $base = max($month->sicknessBase ?? 0, $calculation->rule('sickness.minimum_base', $month->first)->minor());
            $bases[] = $month->key().' '.Czk::text($base);
            $total += Czk::percentUp($base, $rate->value);
        }
        $calculation->sickness = $total;

        return new Step(
            Step::SICKNESS,
            ['months' => (string) \count($months), 'bases' => implode(', ', $bases), 'rate' => $rate->value],
            \sprintf('For each month it is on: %s %% of the chosen monthly base, at least the minimum base, rounded up.', $rate->value),
            $rate->sourceUrl,
            Czk::text($total),
        );
    }
}
