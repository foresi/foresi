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
 * The tax credits (ZDP § 35ba, § 35bb; from 2027 also § 35bc to § 35be): the
 * taxpayer's always, the spouse's for the months the profile claims, and the
 * others of the rule set listed with what they need. Together they lower the
 * tax down to zero, never below.
 */
final readonly class CreditsStep implements PipelineStep
{
    public function apply(Calculation $calculation): Step
    {
        $taxpayer = $calculation->rule('credit.taxpayer');
        $lines = [new CreditLine('taxpayer', true, Czk::money($taxpayer->minor()), \sprintf('Every taxpayer: %s a year.', Czk::words($taxpayer->minor())))];

        $spouseRule = $calculation->rule('credit.spouse');
        $spouse = $calculation->inputs->spouse;
        $spouseAmount = 0;
        if ($spouse instanceof \Foresi\TaxesAndContributions\Contract\SpouseInput && $spouse->lowIncome && $spouse->months > 0) {
            $spouseAmount = intdiv($spouseRule->minor() * min(12, $spouse->months), 12) * ($spouse->ztpP ? 2 : 1);
        }
        $lines[] = new CreditLine('spouse', $spouseAmount > 0, Czk::money($spouseAmount), \sprintf('A spouse in the household with your child under 3, whose own income is at most 68,000 CZK a year: %s a year, 1/12 for each month, double with a ZTP/P card.', Czk::words($spouseRule->minor())));
        foreach (['disability' => 'Your own disability of the first or second degree: %s a year.', 'disability_third' => 'Your own disability of the third degree: %s a year.', 'ztp_p_holder' => 'You hold a ZTP/P card: %s a year.', 'student' => 'You study, up to the age of 26 (28 for a doctorate): %s a year.', 'school_fees' => 'The fees of your child\'s pre-school, up to %s per child a year.', 'eet' => 'The first year you record sales: %s, at most 15 %% of the business base less the taxpayer credit.'] as $key => $explanation) {
            if ($calculation->has('credit.'.$key)) {
                $lines[] = new CreditLine($key, false, Czk::money(0), \sprintf($explanation, Czk::words($calculation->rule('credit.'.$key)->minor())));
            }
        }

        $claimed = $taxpayer->minor() + $spouseAmount;
        $applied = min($claimed, $calculation->tax);
        $calculation->taxAfterCredits = $calculation->tax - $applied;
        $calculation->credits = $lines;

        return new Step(
            Step::CREDITS,
            ['tax' => Czk::text($calculation->tax), 'taxpayer' => Czk::text($taxpayer->minor()), 'spouse' => Czk::text($spouseAmount), 'claimed' => Czk::text($claimed), 'applied' => Czk::text($applied)],
            'The credits claimed lower the tax, down to zero.',
            $taxpayer->sourceUrl,
            Czk::text($calculation->taxAfterCredits),
        );
    }
}
