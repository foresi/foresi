<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Calculation;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechFlatRate;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Czk;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Rules2026;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\RuleValue;
use Foresi\TaxesAndContributions\Contract\Step;

/**
 * The flat-rate tax of the months in it (ZDP § 2a, § 38lk): each pays its
 * band's monthly amount by the rules of that month, which replaces social,
 * health and income tax; no credit, deduction or child benefit applies. The
 * income of the year above the band's limit for any activity (ZDP § 2a (5))
 * gives a warning only: higher limits hold when most of the income comes
 * from activities with 80 % or 60 % lump sums, and the statute tests the
 * year before.
 */
final readonly class FlatRateStep implements PipelineStep
{
    public const string WARNING_OVER_INCOME_LIMIT = 'flat_rate_over_income_limit';

    public function apply(Calculation $calculation): Step
    {
        $rule = static fn (string $key, \DateTimeImmutable $day): RuleValue => $calculation->rule($key, $day);
        $months = [];
        $bands = [];
        [$tax, $social, $health] = [0, 0, 0];
        $source = Rules2026::FLAT_RATE;
        $highest = '1';
        foreach ($calculation->flatRateMonths() as $index => $month) {
            [$flat, $unverified] = CzechFlatRate::month($month, $rule);
            if ($unverified) {
                $calculation->flag(CzechFlatRate::UNVERIFIED_2027_BAND_1);
            }
            $months[] = $flat;
            $bands[$flat->band] = ($bands[$flat->band] ?? 0) + 1;
            $highest = max($highest, $flat->band);
            $tax += $flat->incomeTax->minor();
            $social += $flat->social->minor();
            $health += $flat->health->minor();
            if (0 === $index) {
                $source = $rule(CzechFlatRate::key($flat->band, 'social'), $month->first)->sourceUrl;
            }
        }
        $calculation->flatRateMonths = $months;
        $calculation->flatRate = $tax + $social + $health;

        $input = [];
        ksort($bands);
        foreach ($bands as $band => $count) {
            $input['band_'.$band.'_months'] = (string) $count;
        }
        $limit = $calculation->rule(CzechFlatRate::key($highest, 'income_limit'));
        if ($calculation->income > $limit->minor()) {
            $calculation->warn(self::WARNING_OVER_INCOME_LIMIT);
        }
        $input += [
            'income_tax_part' => Czk::text($tax),
            'social_part' => Czk::text($social),
            'health_part' => Czk::text($health),
            'income' => Czk::text($calculation->income),
            'income_limit' => Czk::text($limit->minor()),
        ];

        return new Step(
            Step::FLAT_RATE,
            $input,
            'For each month in the flat-rate tax, its band\'s monthly amount, which replaces social, health and income tax; no credit, deduction or child benefit applies.',
            $source,
            Czk::text($calculation->flatRate),
        );
    }
}
