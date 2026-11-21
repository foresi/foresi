<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Steps;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Calculation;
use Foresi\TaxesAndContributions\Contract\Step;

/**
 * One step of the Czech sole trader's pipeline (old T9): it reads what the
 * steps before it left in the calculation, leaves its own result there, and
 * says how it got it.
 */
interface PipelineStep
{
    public function apply(Calculation $calculation): Step;
}
