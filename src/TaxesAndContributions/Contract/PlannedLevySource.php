<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The planned items entered by hand for the levies, which the estimate flows
 * may duplicate (B5). Cash flow gives them through Integration/CashFlow; it
 * only reads, and changes or ends nothing.
 */
#[AutoconfigureTag(self::TAG)]
interface PlannedLevySource
{
    public const string TAG = 'app.taxes_planned_levy_source';

    /**
     * The active planned items in the categories taxes and insurance.
     *
     * @return list<PossibleDuplicate>
     */
    public function activeLevyItems(): array;
}
