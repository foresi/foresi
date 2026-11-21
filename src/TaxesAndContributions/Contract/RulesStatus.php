<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * How sure the rules of a year are: published by the authorities, derived
 * from the statute before they are published, or the latest year's rules used
 * for a year that has none yet.
 */
enum RulesStatus: string
{
    case Published = 'published';
    case Derived = 'derived';
    case LatestUsed = 'latest_used';
}
