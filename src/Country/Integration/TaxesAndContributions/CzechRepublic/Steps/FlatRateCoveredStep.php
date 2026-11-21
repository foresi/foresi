<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Calculation;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Czk;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Rules2026;
use Foresi\TaxesAndContributions\Contract\Step;

/**
 * A step of the ordinary pipeline in a year whose every active month is in
 * the flat-rate tax: the band's amount covers social, health and income tax,
 * and no credit, deduction or child benefit applies (ZDP § 2a, § 7a), so it
 * leaves nothing and counts no month.
 */
final readonly class FlatRateCoveredStep implements PipelineStep
{
    public function __construct(private string $key)
    {
    }

    public function apply(Calculation $calculation): Step
    {
        return new Step(
            $this->key,
            ['flat_rate_months' => (string) \count($calculation->flatRateMonths())],
            'Every active month is in the flat-rate tax, whose amount covers this; no credit, deduction or child benefit applies.',
            Rules2026::ZDP,
            Czk::text(0),
        );
    }
}
