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
 * Social insurance for the year as the annual overview settles it: 55 % of
 * the profit, at least the monthly minimum base of each month that takes part,
 * at most 48 average wages, times 29.2 %. The settlement takes the minimum
 * valid in it, so 2026 counts 35 % of the average wage for all of its main
 * months (Act 90/2026). A secondary activity takes part only when the profit
 * reaches the decisive amount, less 1/12 for each month without it; then its
 * months count the 11 % minimum. In a year with both, the profit share is
 * taken for the months that take part (unverified, the mixed year).
 */
final readonly class SocialStep implements PipelineStep
{
    public function apply(Calculation $calculation): Step
    {
        $main = $calculation->monthsOf(Month::MAIN);
        $secondary = $calculation->monthsOf(Month::SECONDARY);
        $averageWage = $calculation->rule('average_wage');
        $share = $calculation->rule('social.profit_share');
        $rate = $calculation->rule('social.rate');
        $input = ['profit' => Czk::text($calculation->profit), 'main_months' => (string) \count($main), 'secondary_months' => (string) \count($secondary)];

        $takesPart = false;
        if ([] !== $secondary) {
            $decisive = Czk::fractionUp($calculation->rule('social.decisive_amount')->minor(), \count($secondary), 12);
            $takesPart = $calculation->profit >= $decisive;
            $input['decisive_amount'] = Czk::text($decisive);
            $input['secondary_takes_part'] = $takesPart ? 'yes' : 'no';
        }
        if ([] !== $main && [] !== $secondary) {
            $calculation->flag(Calculation::UNVERIFIED_MIXED_YEAR);
        }
        $counted = [...$main, ...($takesPart ? $secondary : [])];
        $active = \count($main) + \count($secondary);
        if ([] === $counted) {
            $calculation->socialBase = 0;
            $calculation->social = 0;

            return new Step(Step::SOCIAL, $input, 'No month takes part in social insurance: no main activity, and a secondary activity below the decisive amount.', $rate->sourceUrl, Czk::text(0));
        }

        $minimum = 0;
        foreach ($counted as $month) {
            $minimum += Czk::percentUp($averageWage->minor(), $this->minimumShare($calculation, $month));
        }
        $profitShare = intdiv(Czk::percent(max(0, $calculation->profit), $share->value) * \count($counted), $active);
        $maximum = $averageWage->minor() * (int) $calculation->rule('social.maximum_multiple')->value;
        $calculation->socialBase = min(Czk::upToCzk(max($profitShare, $minimum)), $maximum);
        $calculation->social = Czk::percentUp($calculation->socialBase, $rate->value);
        $input += ['profit_share' => Czk::text($profitShare), 'minimum_base' => Czk::text($minimum), 'maximum_base' => Czk::text($maximum), 'base' => Czk::text($calculation->socialBase), 'rate' => $rate->value];

        return new Step(
            Step::SOCIAL,
            $input,
            \sprintf('%s %% of the profit, at least the minimum base of each month taking part, at most %s average wages; times %s %%, rounded up.', $share->value, $calculation->rule('social.maximum_multiple')->value, $rate->value),
            $rate->sourceUrl,
            Czk::text($calculation->social),
        );
    }

    private function minimumShare(Calculation $calculation, Month $month): string
    {
        if (Month::SECONDARY === $month->activity) {
            return $calculation->rule('social.minimum_share.secondary', $month->first)->value;
        }
        if ($calculation->isStarting()) {
            $calculation->flag(Calculation::UNVERIFIED_STARTING_MINIMUM);

            return $calculation->rule('social.minimum_share.starting', $month->first)->value;
        }

        return $calculation->rule('social.minimum_share.main.settlement', $month->first)->value;
    }
}
